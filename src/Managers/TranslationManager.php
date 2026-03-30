<?php

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\EavModels;

/**
 * Manages locales and persists translation data for any translatable model.
 *
 * Works with any Eloquent model that exposes a translations() MorphToMany relation.
 * This includes EAV models (Attribute, AttributeGroup, AttributeEnum) as well as
 * any application-level models that share the same translations table.
 */
class TranslationManager
{
    public function __construct(
        protected LocaleRegistry $localeRegistry,
    ) {
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function getLocales(?callable $modifier = null): mixed
    {
        $query = EavModels::query('locale');

        return $modifier ? $modifier($query) : $query->get();
    }

    public function getLocale(int $id): Locale
    {
        return EavModels::query('locale')->findOrFail($id);
    }

    public function createLocale(array $data): Locale
    {
        $locale = EavModels::query('locale')->create($data);

        $this->localeRegistry->flush();

        return $locale;
    }

    public function updateLocale(Locale $locale, array $data): Locale
    {
        $locale->update($data);

        $this->localeRegistry->flush();

        return $locale;
    }

    public function deleteLocale(Locale $locale): void
    {
        $locale->delete();

        $this->localeRegistry->flush();
    }

    /**
     * Persist translations for any model with a translations() MorphToMany relation.
     *
     * Entries without a label are discarded. Optional params (hint, placeholder,
     * short_name) are packed into the params column; absent values are omitted.
     *
     * @param  array<int, array<string, mixed>>  $translations
     */
    public function save(Model $model, array $translations): void
    {
        /** @var array<int, array<string, mixed>> $indexed */
        $indexed = array_filter(
            array_column($translations, null, 'locale_id'),
            static fn ($t) => ! is_null($t['label'] ?? null),
        );

        foreach ($indexed as $localeId => $translation) {
            $params = array_filter([
                'short_name'  => $translation['short_name'] ?? null,
                'hint'        => $translation['hint'] ?? null,
                'placeholder' => $translation['placeholder'] ?? null,
            ], static fn ($value) => $value !== null);

            $indexed[$localeId]['params'] = $params ?: null;
        }

        $model->translations()->sync($indexed);
    }
}
