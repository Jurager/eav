<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Translator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;

/** Creates fluent locale and label builders, exposed via the Translator facade. */
class TranslatorFactory
{
    public function __construct(
        private readonly TranslationManager $manager,
        private readonly LocaleRegistry $locales,
    ) {
    }

    /**
     * Start building a locale.
     *
     * Pass a code to create a new locale, or an existing `Locale` to update it.
     */
    public function locale(Locale|string $code): LocaleBuilder
    {
        return new LocaleBuilder($this->manager, $code);
    }

    /** Start building the translated labels for a model. */
    public function for(Model $model): LabelBuilder
    {
        return new LabelBuilder($this->manager, $this->locales, $model);
    }

    /**
     * Persist the queued labels of many builders in a single batched upsert —
     * for imports, not one-off use.
     *
     * @param  array<int, LabelBuilder>  $builders
     */
    public function batch(array $builders, ?Carbon $timestamp = null): void
    {
        $this->manager->batch(
            array_map(fn (LabelBuilder $builder) => $builder->build(), $builders),
            $timestamp,
        );
    }

    /** Find a locale by ID. */
    public function findLocale(int $id): Locale
    {
        return $this->manager->locale($id);
    }

    /** Query builder for locales. */
    public function locales(?callable $modifier = null): mixed
    {
        return $this->manager->locales($modifier);
    }
}
