<?php

declare(strict_types=1);

namespace Jurager\Eav\Translation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Manage locales and translated model labels.
 */
class Translator
{
    public function __construct(
        private readonly LocaleRepository $locales,
        private readonly TranslationRepository $translations,
        private readonly LocaleRegistry $registry,
    ) {}

    /**
     * Start building a locale: pass a code to create one, or an existing locale to update it.
     */
    public function locale(Locale|string $locale): LocaleBuilder
    {
        return new LocaleBuilder($this->locales, $locale);
    }

    /**
     * Start building the translated labels of a model.
     */
    public function for(Model $model): LabelBuilder
    {
        return new LabelBuilder($this->translations, $this->registry, $model);
    }

    /**
     * Save labels from multiple builders in bulk.
     *
     * @param  list<LabelBuilder>  $builders
     */
    public function batch(array $builders, ?Carbon $timestamp = null): void
    {
        $this->translations->batch(
            array_map(static fn (LabelBuilder $builder): array => $builder->build(), $builders),
            $timestamp,
        );
    }

    public function findLocale(int $id): Locale
    {
        return $this->locales->find($id);
    }

    /**
     * All locales, or whatever the modifier returns for the locale query (e.g. a paginator).
     *
     * @param  (callable(\Illuminate\Database\Eloquent\Builder<Locale>): mixed)|null  $modifier
     */
    public function locales(?callable $modifier = null): mixed
    {
        $query = $this->locales->query();

        return $modifier !== null ? $modifier($query) : $query->get();
    }
}
