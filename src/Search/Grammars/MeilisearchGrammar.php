<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Grammars;

use Illuminate\Contracts\Config\Repository;
use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Filters\Condition;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Writes searches, their conditions and index settings the way Meilisearch reads them.
 */
class MeilisearchGrammar
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
     * The search request of a builder, without paging and facets.
     *
     * @param  list<string>  $except  Paths of fields whose top-level conditions are left out.
     * @param  bool|null  $side  Which side of the partition to take: its matches, or the rest; null for both.
     * @param  Group|null  $boosted  A boosted condition to narrow the search to.
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException
     */
    public function compileSearch(Builder $builder, array $except = [], ?bool $side = null, ?Group $boosted = null): array
    {
        $request = [
            'indexUid' => $builder->uid(),
            'showRankingScore' => true,
            ...$this->text($builder),
        ];

        if (($filter = $this->filter($builder, $except, $side, $boosted)) !== null) {
            $request['filter'] = $filter;
        }

        foreach ($builder->sorts as [$field, $direction]) {
            $request['sort'][] = $builder->path($field).':'.$direction;
        }

        if ($builder->distinct !== null) {
            $request['distinct'] = $builder->filterable($builder->distinct)->path;
        }

        if ($builder->highlight !== null) {
            $request['attributesToHighlight'] = $builder->highlight === []
                ? ($request['attributesToSearchOn'] ?? ['*'])
                : array_map(static fn (string $name): string => $builder->path($builder->field($name)), $builder->highlight);
        }

        return [...$request, ...$builder->options];
    }

    /**
     * The text to match, the fields to match it against, and the languages it may be written in.
     *
     * @return array<string, mixed>
     */
    private function text(Builder $builder): array
    {
        if (($text = $builder->text()) === null) {
            return [];
        }

        $request = ['q' => $text];

        if (($paths = $this->searchOn($builder)) !== []) {
            $request['attributesToSearchOn'] = $paths;
        }

        $languages = array_values(array_unique(array_filter(array_map($this->language(...), $builder->locales()))));
        $localized = $builder->definition->paths(static fn (IndexField $field): bool => $field->localized && $field->searchable);

        if ($languages !== [] && $localized !== []) {
            $request['locales'] = $languages;
        }

        return $request;
    }

    /**
     * Paths the text is matched against: every searchable field, or the ones the builder narrows to.
     *
     * @return list<string>
     */
    private function searchOn(Builder $builder): array
    {
        if ($builder->searchOn === null) {
            return $builder->definition->paths(static fn (IndexField $field): bool => $field->searchable, $builder->locales());
        }

        $paths = [];

        foreach ($builder->searchOn as $name) {
            array_push($paths, ...$builder->field($name)->paths($builder->locales()));
        }

        return array_values(array_unique($paths));
    }

    /**
     * Every condition of the builder as one expression, each kept apart in parentheses.
     *
     * @param  list<string>  $except
     */
    private function filter(Builder $builder, array $except, ?bool $side, ?Group $boosted): ?string
    {
        $locale = $builder->locales()[0] ?? null;
        $filters = [];

        foreach ($builder->conditions as $group) {
            $filters[] = $this->compileFilter($group->except($except), $locale);
        }

        if ($side !== null && $builder->partition !== null) {
            $condition = $this->compileFilter($builder->partition, $locale);
            $filters[] = $condition === null || $side ? $condition : "NOT ({$condition})";
        }

        if ($boosted !== null) {
            $filters[] = $this->compileFilter($boosted, $locale);
        }

        // Scout's own wheres: the soft-delete marker, or anything a caller pushed onto the builder directly.
        foreach ($builder->wheres as $where) {
            $filters[] = $where['field'].' '.$where['operator'].' '.match (true) {
                is_bool($where['value']) => $where['value'] ? 'true' : 'false',
                is_numeric($where['value']) => (string) $where['value'],
                default => '"'.addcslashes((string) $where['value'], '"\\').'"',
            };
        }

        $filters = array_filter($filters);

        return $filters === [] ? null : implode(' AND ', array_map(static fn (string $filter): string => "({$filter})", $filters));
    }


    /**
     * Checked conditions as a filter expression.
     *
     * @param  string|null  $locale  Locale whose slot of a localized field is compared.
     *
     * @throws InvalidSearchQueryException When a condition needs a feature that is turned off.
     */
    public function compileFilter(Group $group, ?string $locale = null): ?string
    {
        $parts = array_map(fn (Condition|Group $member): string => $this->member($member, $locale), $group->members);

        return $parts === [] ? null : implode($group->any ? ' OR ' : ' AND ', $parts);
    }

    private function member(Condition|Group $member, ?string $locale): string
    {
        if ($member instanceof Condition) {
            return $this->condition($member, $member->field->pathFor($locale));
        }

        $expression = (string) $this->compileFilter($member, $locale);

        return count($member->members) > 1 ? "({$expression})" : $expression;
    }

    private function condition(Condition $condition, string $path): string
    {
        $value = $condition->value;

        return match ($condition->comparison) {
            Comparison::Equal => "{$path} = ".self::literal($value),
            Comparison::NotEqual => "{$path} != ".self::literal($value),
            Comparison::Greater => "{$path} > ".self::literal($value),
            Comparison::GreaterOrEqual => "{$path} >= ".self::literal($value),
            Comparison::Less => "{$path} < ".self::literal($value),
            Comparison::LessOrEqual => "{$path} <= ".self::literal($value),
            Comparison::In => "{$path} IN [".implode(', ', array_map(self::literal(...), $value)).']',
            Comparison::NotIn => "NOT {$path} IN [".implode(', ', array_map(self::literal(...), $value)).']',
            Comparison::Between => "({$path} >= ".self::literal($value[0])." AND {$path} <= ".self::literal($value[1]).')',
            Comparison::NotBetween => "({$path} < ".self::literal($value[0])." OR {$path} > ".self::literal($value[1]).')',
            Comparison::Exists => "{$path} EXISTS",
            Comparison::Missing => "{$path} NOT EXISTS",
            Comparison::Contains => $this->contains($condition, $path),
        };
    }

    /**
     * Substring match — an experimental Meilisearch feature, off unless enabled in the configuration.
     */
    private function contains(Condition $condition, string $path): string
    {
        if (! $this->config->get('eav.search.meilisearch.contains', false)) {
            throw InvalidSearchQueryException::unsupportedOperator($condition->name, $condition->comparison->value);
        }

        return "{$path} CONTAINS ".self::literal($condition->value);
    }

    private static function literal(string|int|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"'.addcslashes($value, '"\\').'"',
            default => (string) $value,
        };
    }

    /**
     * The settings of an index: what Scout is configured with, plus what the definition requires.
     *
     * @return array<string, mixed>
     */
    public function compileSettings(Index $index): array
    {
        // What the application configured for Scout comes first: by model class, or by index name.
        $configured = (array) $this->config->get('scout.meilisearch.index-settings', []);

        $settings = array_replace_recursive(
            (array) ($configured[$index->model::class] ?? $configured[$index->uid()] ?? []),
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
        $language = $this->config->get("eav.search.meilisearch.locales.{$locale}") ?? strtolower(substr($locale, 0, 2));

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
