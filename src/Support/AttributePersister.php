<?php

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use LogicException;

/**
 * Handles persistence of EAV attribute values and their translations.
 *
 * Two modes:
 *   Single-entity — construct with $entity, call persist() / saveField() / syncFields().
 *   Batch         — construct without arguments, stage with add(), execute with flush().
 *
 * Localizable fields: value column stays null, actual values go to entity_translations.
 * Non-localizable fields: value stored in the typed column, no translations written.
 */
class AttributePersister
{
    /** PDO bind parameter limit — applies to both MySQL and PostgreSQL. */
    private const int BIND_LIMIT = 65535;

    private const array VALUE_COLUMNS = [
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    private const array NULL_COLUMNS = [
        'value_text' => null,
        'value_integer' => null,
        'value_float' => null,
        'value_boolean' => null,
        'value_date' => null,
        'value_datetime' => null,
    ];

    /** @var array<string, array<int|string, Collection<int, Field>>> */
    private array $items = [];

    /**
     * @param  Attributable|null  $entity  Provide for single-entity mode; omit for batch mode.
     */
    public function __construct(
        private readonly ?Attributable $entity = null,
    ) {}

    /**
     * Persist a collection of filled fields for the current entity.
     *
     * @param  Collection<int, Field>  $filled
     */
    public function persist(Collection $filled): void
    {
        if ($this->entity === null || $filled->isEmpty()) {
            return;
        }

        $this->persistGroup(
            $this->entity->getAttributeEntityType(),
            [$this->entity->id => $filled],
        );
    }

    /**
     * Persist a single field.
     */
    public function saveField(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /**
     * Persist the given fields and delete all entity_attribute rows not in this set.
     *
     * @param  Collection<int, Field>  $filled
     */
    public function syncFields(Collection $filled): void
    {
        $keepIds = $filled->map(fn (Field $f) => $f->attribute()->id)->values()->all();

        $this->deleteExcluding($keepIds);
        $this->persist($filled);
    }

    /**
     * Delete entity_attribute rows for the current entity whose attribute_id is NOT in $attributeIds.
     *
     * @param  array<int>  $attributeIds  Attribute IDs to keep.
     */
    public function deleteExcluding(array $attributeIds): void
    {
        $ids = $this->entityQuery()
            ->whereNotIn('attribute_id', $attributeIds)
            ->pluck('id')
            ->all();

        $this->delete($ids);
    }

    /**
     * Delete entity_attribute rows for the current entity matching the given attribute IDs.
     *
     * @param  array<int>  $attributeIds
     */
    public function detachByAttributeIds(array $attributeIds): void
    {
        $ids = $this->entityQuery()
            ->whereIn('attribute_id', $attributeIds)
            ->pluck('id')
            ->all();

        $this->delete($ids);
    }

    /**
     * Delete entity_attribute records and their translations by record IDs.
     *
     * @param  array<int>  $recordIds  entity_attribute primary key IDs.
     */
    public function delete(array $recordIds): void
    {
        if (empty($recordIds)) {
            return;
        }

        EavModels::query('entity_translation')
            ->where('entity_type', 'entity_attribute')
            ->whereIn('entity_id', $recordIds)
            ->delete();

        EavModels::query('entity_attribute')->whereIn('id', $recordIds)->delete();
    }

    /**
     * Stage fields for a single entity. Call flush() when the batch is complete.
     *
     * @param  Collection<int, Field>  $fields
     */
    public function add(Attributable $entity, Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $this->items[$entity->getAttributeEntityType()][$entity->id] = $fields;
    }

    /**
     * Persist all staged items and clear the queue.
     */
    public function flush(): void
    {
        foreach ($this->items as $entityType => $entitiesByType) {
            $this->persistGroup($entityType, $entitiesByType);
        }

        $this->items = [];
    }

    /**
     * Core persistence logic shared by single-entity and batch modes.
     *
     * 1. Load existing rows for the group in one query.
     * 2. Partition all fields into update / insert / delete + translation buckets.
     * 3. Execute the minimal set of batch DB operations.
     *
     * @param  array<int|string, Collection<int, Field>>  $entitiesByType
     */
    private function persistGroup(string $entityType, array $entitiesByType): void
    {
        if (empty($entitiesByType)) {
            return;
        }

        $now = now();
        $attrIds = collect($entitiesByType)
            ->flatten()
            ->map(fn (Field $f) => $f->attribute()->id)
            ->unique()
            ->values()
            ->all();

        if (empty($attrIds)) {
            return;
        }

        $existingByKey = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', array_keys($entitiesByType))
            ->whereIn('attribute_id', $attrIds)
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id'])
            ->groupBy(fn ($r) => $r->entity_id.':'.$r->attribute_id);

        $existingIds = $existingByKey->flatten()->pluck('id')->all();

        [
            'toUpdate' => $toUpdate,
            'toInsert' => $toInsert,
            'toDelete' => $toDelete,
            'insertTranslations' => $insertTranslations,
            'updateTranslations' => $updateTranslations,
        ] = $this->partitionGroup($entityType, $entitiesByType, $existingByKey, $now);

        if (! empty($toDelete)) {
            $this->delete($toDelete);
        }

        $this->upsertRows($toUpdate);

        if (! empty($toInsert)) {
            $this->insertRows($toInsert);

            $localizableRows = array_filter($insertTranslations);
            if (! empty($localizableRows)) {
                $newRecords = $this->fetchInsertedRecords($entityType, $toInsert, $existingIds);
                $translationsToInsert = $this->alignTranslations($toInsert, $newRecords, $insertTranslations);
                $this->insertTranslationRows($this->buildTranslationRows($translationsToInsert, $now));
            }
        }

        $this->syncTranslations($updateTranslations, $now);
    }

    /**
     * Partition all fields in the group into update / insert / delete / translation buckets.
     *
     * Field::toStorage() defines the wire format:
     *   non-localizable → [{value: X, translations: []}]
     *   localizable     → [{value: null, translations: [{locale_id, value}, …]}]
     *
     * Non-localizable fields write to the typed column; localizable fields keep the column
     * null and store values in entity_translations via syncTranslations() / insertTranslationRows().
     *
     * @param  array<int|string, Collection<int, Field>>  $entitiesByType
     * @return array{toUpdate: array, toInsert: array, toDelete: array, insertTranslations: array, updateTranslations: array}
     */
    private function partitionGroup(
        string $entityType,
        array $entitiesByType,
        Collection $existingByKey,
        Carbon $now,
    ): array {
        $toUpdate = $toInsert = $toDelete = $insertTranslations = $updateTranslations = [];

        foreach ($entitiesByType as $entityId => $fields) {
            foreach ($fields as $field) {
                $attrId = $field->attribute()->id;
                $column = $field->column();
                $localizable = $field->isLocalizable();
                $items = $field->toStorage();
                $itemCount = count($items);
                $existing = $existingByKey->get($entityId.':'.$attrId, collect())->values();

                foreach ($existing->take($itemCount) as $i => $record) {
                    $item = $items[$i];
                    $row = $this->blankRow($entityType, $entityId, $attrId, $now);
                    $row['id'] = $record->id;
                    $row[$column] = $localizable ? null : ($item['value'] ?? null);
                    $toUpdate[] = $row;

                    if ($localizable) {
                        // Sync the full locale set; an empty array causes syncTranslations()
                        // to delete all existing translations for this record.
                        $updateTranslations[$record->id] = $item['translations'] ?? [];
                    }
                }

                foreach (array_slice($items, $existing->count()) as $item) {
                    $row = $this->blankRow($entityType, $entityId, $attrId, $now);
                    $row[$column] = $localizable ? null : ($item['value'] ?? null);
                    $toInsert[] = $row;
                    // Parallel index to $toInsert — used in alignTranslations().
                    $insertTranslations[] = $localizable ? ($item['translations'] ?? []) : [];
                }

                foreach ($existing->slice($itemCount) as $record) {
                    $toDelete[] = $record->id;
                }
            }
        }

        return compact('toUpdate', 'toInsert', 'toDelete', 'insertTranslations', 'updateTranslations');
    }

    /**
     * Replace translations for existing records: DELETE old, INSERT new.
     *
     * @param  array<int, array<int, array{locale_id: int, value: mixed}>>  $translationsByRecordId
     */
    private function syncTranslations(array $translationsByRecordId, Carbon $now): void
    {
        if (empty($translationsByRecordId)) {
            return;
        }

        EavModels::query('entity_translation')
            ->where('entity_type', 'entity_attribute')
            ->whereIn('entity_id', array_keys($translationsByRecordId))
            ->delete();

        $this->insertTranslationRows($this->buildTranslationRows($translationsByRecordId, $now));
    }

    /**
     * SELECT back the records just inserted, ordered to align positionally
     * with $insertedRows and the parallel $insertTranslations array.
     *
     * @param  array<int, array>  $insertedRows
     * @param  array<int, int>  $existingIds  IDs that existed before this batch.
     */
    private function fetchInsertedRecords(string $entityType, array $insertedRows, array $existingIds): Collection
    {
        return EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', array_unique(array_column($insertedRows, 'entity_id')))
            ->whereIn('attribute_id', array_unique(array_column($insertedRows, 'attribute_id')))
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderBy('entity_id')
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id']);
    }

    /**
     * Map newly inserted record IDs to their translation payloads.
     *
     * @param  array<int, array>  $insertedRows
     * @param  array<int, array<int, array>>  $insertTranslations  Parallel to $insertedRows.
     * @return array<int, array<int, array>> Map of record ID => translation payload.
     */
    private function alignTranslations(array $insertedRows, Collection $newRecords, array $insertTranslations): array
    {
        $idxByKey = [];
        foreach ($insertedRows as $idx => $row) {
            $idxByKey[$row['entity_id'].':'.$row['attribute_id']][] = $idx;
        }

        $result = [];
        foreach ($newRecords->groupBy(fn ($r) => $r->entity_id.':'.$r->attribute_id) as $key => $records) {
            foreach ($records->values() as $pos => $record) {
                $idx = $idxByKey[$key][$pos] ?? null;
                if ($idx !== null && ! empty($insertTranslations[$idx])) {
                    $result[$record->id] = $insertTranslations[$idx];
                }
            }
        }

        return $result;
    }

    /**
     * Build flat entity_translations rows from a record_id => translations map.
     * Uses the format produced by Field::toStorage(): {locale_id, value}.
     *
     * @param  array<int, array<int, array{locale_id: int, value: mixed}>>  $translationsByRecordId
     * @return array<int, array>
     */
    private function buildTranslationRows(array $translationsByRecordId, Carbon $now): array
    {
        $rows = [];

        foreach ($translationsByRecordId as $recordId => $translations) {
            foreach ($translations as $t) {
                if (! isset($t['locale_id'])) {
                    continue;
                }
                $localeId = (int) $t['locale_id'];
                $rows[$recordId.':'.$localeId] = [
                    'entity_type' => 'entity_attribute',
                    'entity_id' => (int) $recordId,
                    'locale_id' => $localeId,
                    'label' => $t['value'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * Upsert entity_attribute rows on primary key, updating value columns and updated_at.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $this->eachChunk($rows, count($rows[0]), function (array $chunk): void {
            EavModels::query('entity_attribute')->upsert(
                $chunk, ['id'], [...self::VALUE_COLUMNS, 'updated_at'],
            );
        });
    }

    /**
     * Bulk-insert entity_attribute rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $this->eachChunk($rows, count($rows[0]), function (array $chunk): void {
            EavModels::query('entity_attribute')->insert($chunk);
        });
    }

    /**
     * Bulk-insert entity_translation rows.
     *
     * @param  array<int, array>  $rows
     */
    private function insertTranslationRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $this->eachChunk($rows, count($rows[0]), function (array $chunk): void {
            EavModels::query('entity_translation')->insert($chunk);
        });
    }

    /**
     * Split rows into bind-safe chunks and invoke $callback for each.
     *
     * Chunk size is derived from the PDO bind parameter limit divided by the column count,
     * which is applicable to both MySQL and PostgreSQL.
     */
    private function eachChunk(array $rows, int $columnCount, callable $callback): void
    {
        $chunkSize = max(1, intdiv(self::BIND_LIMIT, max(1, $columnCount)));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Build a row with all value columns null and required metadata.
     * The caller sets the single typed column before batching.
     *
     * @return array<string, mixed>
     */
    private function blankRow(string $entityType, int|string $entityId, int $attrId, Carbon $now): array
    {
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'attribute_id' => $attrId,
            ...self::NULL_COLUMNS,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Return a Builder scoped to entity_attribute rows for the current entity.
     *
     * @throws LogicException When called in batch mode (no entity).
     */
    private function entityQuery(): Builder
    {
        if ($this->entity === null) {
            throw new LogicException('entityQuery() requires an entity. Use new AttributePersister($entity).');
        }

        return EavModels::query('entity_attribute')
            ->where('entity_type', $this->entity->getAttributeEntityType())
            ->where('entity_id', $this->entity->id);
    }
}
