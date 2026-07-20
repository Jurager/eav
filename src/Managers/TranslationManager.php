<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;

class TranslationManager
{
    public function __construct(
        protected LocaleRegistry $localeRegistry,
    ) {
    }

    /** Execute a custom query on locales or return all. */
    public function locales(?callable $modifier = null): mixed
    {
        $query = Eav::$localeModel::query();

        return $modifier ? $modifier($query) : $query->get();
    }

    /** Find a locale by ID. */
    public function locale(int $id): Locale
    {
        return Eav::$localeModel::query()->findOrFail($id);
    }

    /** Create a new locale. */
    public function create(array $data): Locale
    {
        $locale = Eav::$localeModel::query()->create($data);
        $this->localeRegistry->forget();

        return $locale;
    }

    /** Update an existing locale. */
    public function update(Locale $locale, array $data): Locale
    {
        $locale->update($data);
        $this->localeRegistry->forget();

        return $locale;
    }

    /** Delete a locale. */
    public function delete(Locale $locale): void
    {
        $locale->delete();
        $this->localeRegistry->forget();
    }

    /**
     * Save translations for a specific model.
     * @throws \JsonException
     */
    public function save(Model $model, array $translations, bool $partial = false): void
    {
        $indexed = $this->indexTranslations($translations);
        $localeIds = array_keys($indexed);

        if (! $partial) {
            Eav::$entityTranslationModel::query()
                ->where('entity_type', $model->getMorphClass())
                ->where('entity_id', $model->getKey())
                ->when($localeIds, fn (Builder $q) => $q->whereNotIn('locale_id', $localeIds))
                ->delete();
        }

        if (empty($indexed)) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($id, $t) => $this->buildTranslationRow($model, $id, $t, $now), array_keys($indexed), $indexed);

        Eav::$entityTranslationModel::query()
            ->upsert($rows, ['entity_type', 'entity_id', 'locale_id'], ['label', 'params', 'updated_at']);
    }

    /**
     * Persist translations for multiple models in a bulk upsert.
     * @throws \JsonException
     */
    public function batch(array $modelsWithTranslations, ?Carbon $timestamp = null): void
    {
        $timestamp ??= now();
        $rows = [];

        foreach ($modelsWithTranslations as [$model, $translations]) {
            foreach ($this->indexTranslations($translations) as $localeId => $translation) {
                $rows[] = $this->buildTranslationRow($model, $localeId, $translation, $timestamp);
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            Eav::$entityTranslationModel::query()
                ->upsert($chunk, ['entity_type', 'entity_id', 'locale_id'], ['label', 'params', 'updated_at']);
        }
    }

    /** Index translations by locale_id. */
    protected function indexTranslations(array $translations): array
    {
        return array_filter(
            array_column($translations, null, 'locale_id'),
            static fn (array $t) => ! is_null($t['label'] ?? null),
        );
    }

    /** Build a single translation row for upsert. */
    protected function buildTranslationRow(Model $model, int $localeId, array $translation, Carbon $timestamp): array
    {
        $params = array_filter([
            'short_name'  => $translation['short_name'] ?? null,
            'hint'        => $translation['hint'] ?? null,
            'placeholder' => $translation['placeholder'] ?? null,
        ], static fn ($value) => $value !== null);

        return [
            'entity_type' => $model->getMorphClass(),
            'entity_id'   => $model->getKey(),
            'locale_id'   => $localeId,
            'label'       => $translation['label'],
            'params'      => $params ? json_encode($params, JSON_THROW_ON_ERROR) : null,
            'created_at'  => $timestamp,
            'updated_at'  => $timestamp,
        ];
    }
}
