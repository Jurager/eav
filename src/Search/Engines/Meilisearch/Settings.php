<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines\Meilisearch;

use Illuminate\Contracts\Config\Repository;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Derive the Meilisearch settings of an index from its definition and the configuration.
 */
class Settings
{
    /**
     * Settings holding lists of document paths, merged rather than replaced.
     */
    private const array PATH_LISTS = ['searchableAttributes', 'filterableAttributes', 'sortableAttributes'];

    /**
     * Languages Meilisearch can be told a field is written in, as ISO 639-1 codes.
     */
    private const array LANGUAGES = [
        'af', 'ak', 'am', 'ar', 'az', 'be', 'bn', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'en', 'eo', 'et', 'fi', 'fr',
        'gu', 'he', 'hi', 'hr', 'hu', 'hy', 'id', 'it', 'jv', 'ja', 'kn', 'ka', 'km', 'ko', 'la', 'lv', 'lt', 'ml',
        'mr', 'mk', 'my', 'ne', 'nl', 'nb', 'or', 'pa', 'fa', 'pl', 'pt', 'ro', 'ru', 'si', 'sk', 'sl', 'sn', 'es',
        'sr', 'sv', 'ta', 'te', 'tl', 'th', 'tk', 'tr', 'uk', 'ur', 'uz', 'vi', 'yi', 'zh', 'zu',
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * Settings of an index: what the definition requires, on top of what the configuration asks for.
     *
     * @return array<string, mixed>
     */
    public function for(Index $index): array
    {
        $settings = array_replace_recursive(
            (array) $this->config->get('scout.meilisearch.index-settings.'.$index->model::class, []),
            (array) $this->config->get('eav.search.engines.meilisearch.settings', []),
            (array) $this->config->get("eav.search.engines.meilisearch.indexes.{$index->entityType}", []),
            $index->ownSettings(),
        );

        $derived = [
            'searchableAttributes' => $index->paths(static fn (IndexField $field): bool => $field->searchable),
            'filterableAttributes' => $index->paths(static fn (IndexField $field): bool => $field->filterable),
            'sortableAttributes' => $index->paths(static fn (IndexField $field): bool => $field->sortable),
        ];

        foreach (self::PATH_LISTS as $key) {
            $settings[$key] = array_values(array_unique([...$derived[$key], ...array_values((array) ($settings[$key] ?? []))]));
        }

        if ($index->softDeletes) {
            $settings['filterableAttributes'][] = Index::SOFT_DELETED;
        }

        if (($distinct = $index->distinctField()) !== null) {
            $settings['distinctAttribute'] = $distinct->path;
        }

        if (($localized = $this->localized($index)) !== []) {
            $settings['localizedAttributes'] = $localized;
        }

        // Codes, numbers and the like match exactly: a typo in an article number is another article.
        $exact = $index->paths(static fn (IndexField $field): bool => $field->searchable && ! $field->type->toleratesTypos());

        if ($exact !== []) {
            $settings['typoTolerance']['disableOnAttributes'] = array_values(array_unique([
                ...$exact,
                ...array_values((array) ($settings['typoTolerance']['disableOnAttributes'] ?? [])),
            ]));
        }

        return $settings;
    }

    /**
     * The engine's language for a locale code, or null when it knows none.
     */
    public function language(string $locale): ?string
    {
        $language = $this->config->get("eav.search.engines.meilisearch.locales.{$locale}") ?? strtolower(substr($locale, 0, 2));

        return in_array($language, self::LANGUAGES, true) ? $language : null;
    }

    /**
     * Tell the engine which language each locale's slot of a localized field is written in.
     *
     * @return list<array{attributePatterns: list<string>, locales: list<string>}>
     */
    private function localized(Index $index): array
    {
        if ($index->paths(static fn (IndexField $field): bool => $field->localized) === []) {
            return [];
        }

        $rules = [];

        foreach ($index->locales() as $locale) {
            if (($language = $this->language($locale)) !== null) {
                $rules[] = ['attributePatterns' => ["*.{$locale}"], 'locales' => [$language]];
            }
        }

        return $rules;
    }
}
