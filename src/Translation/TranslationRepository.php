<?php

declare(strict_types=1);

namespace Jurager\Eav\Translation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JsonException;
use Jurager\Eav\Eav;
use Jurager\Eav\Registry\SchemaVersion;

/**
 * Reads and writes the `entity_translations` rows holding the labels of any model.
 */
class TranslationRepository
{
    public function __construct(private readonly SchemaVersion $version) {}

    /**
     * Save translations for a specific model.
     *
     * @throws JsonException
     */
    public function save(Model $model, array $translations, bool $partial = false): void
    {
        $indexed = $this->indexTranslations($translations);
        $localeIds = array_keys($indexed);

        // Labels belong to the schema: every process reads them again.
        $this->version->bump();

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
     *
     * @throws JsonException
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

        if ($rows !== []) {
            $this->version->bump();
        }
    }

    /**
     * Index translations by locale_id.
     */
    protected function indexTranslations(array $translations): array
    {
        return array_filter(
            array_column($translations, null, 'locale_id'),
            static fn (array $t) => ! is_null($t['label'] ?? null),
        );
    }

    /**
     * Build a single translation row for upsert.
     */
    protected function buildTranslationRow(Model $model, int $localeId, array $translation, Carbon $timestamp): array
    {
        $params = array_filter([
            'short_name' => $translation['short_name'] ?? null,
            'hint' => $translation['hint'] ?? null,
            'placeholder' => $translation['placeholder'] ?? null,
        ], static fn ($value) => $value !== null);

        return [
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
