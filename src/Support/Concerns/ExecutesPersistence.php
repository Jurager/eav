<?php

namespace Jurager\Eav\Support\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Eav;

/**
 * Core EAV persistence engine shared between AttributePersister and BatchAttributePersister.
 */
trait ExecutesPersistence
{
    private const int BIND_LIMIT = 65535;

    private const string MODEL_ATTRIBUTE = 'entity_attribute';

    private const string MODEL_TRANSLATION = 'entity_translation';

    private const array VALUE_COLUMNS = [
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    private const array TRANSLATION_VALUE_COLUMNS = ['label', 'updated_at'];

    private ?Carbon $timestamp = null;

    /** @param  array<int>  $ids */
    public function delete(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        Eav::$entityTranslationModel::query()
            ->where('entity_type', self::MODEL_ATTRIBUTE)
            ->whereIn('entity_id', $ids)
            ->delete();

        Eav::$entityAttributeModel::query()
            ->whereIn('id', $ids)
            ->delete();
    }

    /** @param  array<int|string, Collection<int, Field>>  $grouped */
    private function persistGroup(string $type, array $grouped): void
    {
        if (empty($grouped)) {
            return;
        }

        $attributeIds = collect($grouped)
            ->flatMap(fn (Collection $fields) => $fields->map(fn (Field $f) => $f->attribute()->id))
            ->unique()
            ->all();

        if (empty($attributeIds)) {
            return;
        }

        $existing = Eav::$entityAttributeModel::query()
            ->where('entity_type', $type)
            ->whereIn('entity_id', array_keys($grouped))
            ->whereIn('attribute_id', $attributeIds)
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id'])
            ->groupBy(fn ($row) => "$row->entity_id:$row->attribute_id");

        ['updates' => $updates, 'inserts' => $inserts, 'deletes' => $deletes] =
            $this->partition($type, $grouped, $existing);

        $this->delete($deletes);
        $this->applyUpdates(collect($updates));
        $this->applyInserts(collect($inserts), $type);
    }

    /** @param  Collection<int, array{row: array, translations: array|null}>  $updates */
    private function applyUpdates(Collection $updates): void
    {
        if ($updates->isEmpty()) {
            return;
        }

        $this->inChunks(
            $updates->pluck('row'),
            fn (Collection $chunk) => Eav::$entityAttributeModel::query()
                ->upsert($chunk->all(), ['id'], [...self::VALUE_COLUMNS, 'updated_at']),
        );

        $this->syncTranslations($updates);
    }

    /** @param  Collection<int, array{row: array, translations: array|null}>  $inserts */
    private function applyInserts(Collection $inserts, string $type): void
    {
        if ($inserts->isEmpty()) {
            return;
        }

        $rows = $inserts->pluck('row');

        $maxIdBefore = (int) (Eav::$entityAttributeModel::query()->max('id') ?? 0);

        $this->inChunks(
            $rows,
            fn (Collection $chunk) => Eav::$entityAttributeModel::query()->insert($chunk->all()),
        );

        $hasTranslations = $inserts->pluck('translations')->contains(fn ($t) => ! empty($t));

        if (! $hasTranslations) {
            return;
        }

        $created = $this->fetchCreatedRecords($type, $rows, $maxIdBefore);
        $mapped = $this->mapTranslationsToRecords($inserts, $created);

        $this->inChunks(
            $this->buildTranslationRows(collect($mapped)),
            fn (Collection $chunk) => Eav::$entityTranslationModel::query()
                ->upsert($chunk->all(), ['entity_type', 'entity_id', 'locale_id'], self::TRANSLATION_VALUE_COLUMNS),
        );
    }

    /** @param  Collection<int, array{row: array, translations: array|null}>  $entries */
    private function syncTranslations(Collection $entries): void
    {
        $translatable = $entries
            ->mapWithKeys(fn ($item) => [$item['row']['id'] => $item['translations']])
            ->filter(fn ($value) => $value !== null);

        if ($translatable->isEmpty()) {
            return;
        }

        $emptyIds = $translatable->filter(fn ($t) => empty($t))->keys();

        if ($emptyIds->isNotEmpty()) {
            Eav::$entityTranslationModel::query()
                ->where('entity_type', self::MODEL_ATTRIBUTE)
                ->whereIn('entity_id', $emptyIds)
                ->delete();
        }

        $withData = $translatable->filter(fn ($t) => ! empty($t));

        if ($withData->isEmpty()) {
            return;
        }

        $this->pruneStaleTranslations($withData);

        $this->inChunks(
            $this->buildTranslationRows($withData),
            fn (Collection $chunk) => Eav::$entityTranslationModel::query()
                ->upsert($chunk->all(), ['entity_type', 'entity_id', 'locale_id'], self::TRANSLATION_VALUE_COLUMNS),
        );
    }

    /** @param  Collection<int, array>  $withData */
    private function pruneStaleTranslations(Collection $withData): void
    {
        $localesByRecord = $withData->map(
            fn (array $translations) => collect($translations)
                ->pluck('locale_id')
                ->filter()
                ->values()
                ->all(),
        );

        $localesByRecord
            ->groupBy(fn ($locales) => implode(',', $locales), preserveKeys: true)
            ->each(function (Collection $group): void {
                $recordIds = $group->keys()->all();
                $keepLocales = $group->first();

                Eav::$entityTranslationModel::query()
                    ->where('entity_type', self::MODEL_ATTRIBUTE)
                    ->whereIn('entity_id', $recordIds)
                    ->whereNotIn('locale_id', $keepLocales)
                    ->delete();
            });
    }

    /**
     * @param  array<int|string, Collection<int, Field>>  $grouped
     * @return array{
     *     updates: array<int, array{row: array, translations: array|null}>,
     *     inserts: array<int, array{row: array, translations: array|null}>,
     *     deletes: array<int>
     * }
     */
    private function partition(string $type, array $grouped, Collection $existing): array
    {
        $updates = $inserts = $deletes = [];

        foreach ($grouped as $entityId => $fields) {
            foreach ($fields as $field) {
                $attrId = $field->attribute()->id;
                $column = $field->column();
                $localizable = $field->isLocalizable();
                $values = $field->toStorage();
                $records = $existing->get("$entityId:$attrId", collect())->values();

                $valueCount = count($values);
                $recordCount = $records->count();
                $overlap = min($valueCount, $recordCount);

                for ($i = 0; $i < $overlap; $i++) {
                    $entry = $this->buildEntry($type, $entityId, $attrId, $column, $localizable, $values[$i]);
                    $entry['row']['id'] = $records[$i]->id;
                    $updates[] = $entry;
                }

                for ($i = $overlap; $i < $valueCount; $i++) {
                    $inserts[] = $this->buildEntry($type, $entityId, $attrId, $column, $localizable, $values[$i]);
                }

                for ($i = $overlap; $i < $recordCount; $i++) {
                    $deletes[] = $records[$i]->id;
                }
            }
        }

        return compact('updates', 'inserts', 'deletes');
    }

    /** @return array{row: array, translations: array|null} */
    private function buildEntry(
        string $type,
        int|string $entityId,
        int $attrId,
        string $column,
        bool $localizable,
        array $storage,
    ): array {
        $row = $this->blankRow($type, $entityId, $attrId);
        $row[$column] = $localizable ? null : ($storage['value'] ?? null);

        return [
            'row' => $row,
            'translations' => $localizable ? ($storage['translations'] ?? []) : null,
        ];
    }

    private function fetchCreatedRecords(string $type, Collection $rows, int $maxIdBefore): Collection
    {
        return Eav::$entityAttributeModel::query()
            ->where('entity_type', $type)
            ->whereIn('entity_id', $rows->pluck('entity_id')->unique())
            ->whereIn('attribute_id', $rows->pluck('attribute_id')->unique())
            ->where('id', '>', $maxIdBefore)
            ->orderBy('entity_id')
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id']);
    }

    /**
     * @param  Collection<int, array{row: array, translations: array|null}>  $inserts
     * @return array<int, array>
     */
    private function mapTranslationsToRecords(Collection $inserts, Collection $created): array
    {
        $payloads = $inserts
            ->groupBy(fn ($item) => "{$item['row']['entity_id']}:{$item['row']['attribute_id']}")
            ->map(fn (Collection $group) => $group->pluck('translations')->all());

        $mapped = [];

        $created
            ->groupBy(fn ($record) => "$record->entity_id:$record->attribute_id")
            ->each(function (Collection $records, string $key) use ($payloads, &$mapped): void {
                foreach ($records->values() as $position => $record) {
                    $translations = $payloads[$key][$position] ?? [];

                    if (! empty($translations)) {
                        $mapped[(int) $record->id] = $translations;
                    }
                }
            });

        return $mapped;
    }

    /** @param  Collection<int, array<int, array{locale_id: int, value: mixed}>>  $map */
    private function buildTranslationRows(Collection $map): Collection
    {
        return $map
            ->flatMap(
                fn (array $translations, int $recordId) => collect($translations)
                    ->filter(fn (array $t) => isset($t['locale_id']))
                    ->mapWithKeys(fn (array $t) => [
                        "$recordId:{$t['locale_id']}" => [
                            'entity_type' => self::MODEL_ATTRIBUTE,
                            'entity_id' => $recordId,
                            'locale_id' => (int) $t['locale_id'],
                            'label' => $t['value'] ?? null,
                            'created_at' => $this->timestamp ?? now(),
                            'updated_at' => $this->timestamp ?? now(),
                        ],
                    ]),
            )
            ->values();
    }

    private function inChunks(Collection $rows, callable $callback): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $columns = count($rows->first() ?? []);
        $size = max(1, intdiv(self::BIND_LIMIT, max(1, $columns)));

        $rows->chunk($size)->each($callback);
    }

    /** @return array<string, mixed> */
    private function blankRow(string $type, int|string $entityId, int $attrId): array
    {
        $ts = $this->timestamp ?? throw new \LogicException('blankRow() called outside withinTimestamp().');

        return [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'attribute_id' => $attrId,
            ...array_fill_keys(self::VALUE_COLUMNS, null),
            'created_at' => $ts,
            'updated_at' => $ts,
        ];
    }

    private function withinTimestamp(callable $callback): void
    {
        $this->timestamp = Carbon::now();

        try {
            $callback();
        } finally {
            $this->timestamp = null;
        }
    }
}
