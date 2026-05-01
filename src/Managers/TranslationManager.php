<?php

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\EavModels;

/**
 * Manages locales and persists translation data for any translatable model.
 */
class TranslationManager
{
    public function __construct(
        protected LocaleRegistry $localeRegistry,
    ) {
    }

    /** @param  callable(Builder): mixed|null  $modifier */
    public function locales(?callable $modifier = null): mixed
    {
        $query = EavModels::query('locale');

        return $modifier ? $modifier($query) : $query->get();
    }

    public function locale(int $id): Locale
    {
        return EavModels::query('locale')->findOrFail($id);
    }

    public function create(array $data): Locale
    {
        $locale = EavModels::query('locale')->create($data);

        $this->localeRegistry->forget();

        return $locale;
    }

    public function update(Locale $locale, array $data): Locale
    {
        $locale->update($data);

        $this->localeRegistry->forget();

        return $locale;
    }

    public function delete(Locale $locale): void
    {
        $locale->delete();

        $this->localeRegistry->forget();
    }

    /**
     * Persist translations for any model with a translations() MorphToMany relation.
     *
     * Entries without a label are discarded. Optional params (hint, placeholder,
     * short_name) are packed into the params column; absent values are omitted.
     *
     * Uses upsert + targeted delete instead of sync() to avoid the non-atomic
     * "delete-all then re-insert" window where the model would briefly have no
     * translations visible to concurrent readers.
     *
     * @param  array<int, array<string, mixed>>  $translations
     *
     * @throws \JsonException
     */
    public function save(Model $model, array $translations): void
    {
        /** @var array<int, array<string, mixed>> $indexed */
        $indexed = array_filter(
            array_column($translations, null, 'locale_id'),
            static fn ($t) => ! is_null($t['label'] ?? null),
        );

        $morphType = $model->getMorphClass();
        $entityId = $model->getKey();
        $localeIds = array_keys($indexed);

        // Remove translations for locales that are no longer in the incoming set.
        EavModels::query('entity_translation')
            ->where('entity_type', $morphType)
            ->where('entity_id', $entityId)
            ->when(
                $localeIds,
                fn ($q) => $q->whereNotIn('locale_id', $localeIds),
            )
            ->delete();

        if (empty($indexed)) {
            return;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($indexed as $localeId => $translation) {
            $params = array_filter([
                'short_name' => $translation['short_name'] ?? null,
                'hint' => $translation['hint'] ?? null,
                'placeholder' => $translation['placeholder'] ?? null,
            ], static fn ($value) => $value !== null);

            $rows[] = [
                'entity_type' => $morphType,
                'entity_id' => $entityId,
                'locale_id' => $localeId,
                'label' => $translation['label'],
                'params' => $params ? json_encode($params, JSON_THROW_ON_ERROR) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        EavModels::query('entity_translation')
            ->upsert($rows, ['entity_type', 'entity_id', 'locale_id'], ['label', 'params', 'updated_at']);
    }

    /**
     * Persist translations for many models in a single bulk upsert.
     *
     * @param  array<int, array{0: Model, 1: array<int, array<string, mixed>>}>  $modelsWithTranslations
     *
     * @throws \JsonException
     */
    public function batch(array $modelsWithTranslations, ?Carbon $timestamp = null): void
    {
        $timestamp ??= Carbon::now();
        $rows = [];

        foreach ($modelsWithTranslations as [$model, $translations]) {
            $indexed = array_filter(
                array_column($translations, null, 'locale_id'),
                static fn ($t) => ! is_null($t['label'] ?? null),
            );

            foreach ($indexed as $localeId => $translation) {
                $params = array_filter([
                    'short_name' => $translation['short_name'] ?? null,
                    'hint' => $translation['hint'] ?? null,
                    'placeholder' => $translation['placeholder'] ?? null,
                ], static fn ($value) => $value !== null);

                $rows[] = [
                    'entity_type' => $model->getMorphClass(),
                    'entity_id' => $model->getKey(),
                    'locale_id' => $localeId,
                    'label' => $translation['label'],
                    'params' => $params ? json_encode($params, JSON_THROW_ON_ERROR) : null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            EavModels::query('entity_translation')
                ->upsert($chunk, ['entity_type', 'entity_id', 'locale_id'], ['label', 'params', 'updated_at']);
        }
    }
}
