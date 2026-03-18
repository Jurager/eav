<?php

namespace Jurager\Eav;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;

/**
 * Handles low-level persistence of attribute field values and translations.
 *
 * Supports two modes that share the same partitioning, insert-resolve and
 * translation-sync internals via persistGroup():
 *
 *   Single-entity — construct with an entity, call persist() / saveField().
 *                   ~6 queries per call regardless of attribute count.
 *
 *   Batch         — construct without arguments, call add() for each entity,
 *                   then flush(). ~7 queries for the entire batch regardless
 *                   of entity or attribute count.
 */
class AttributePersister
{
    /**
     * Typed storage columns in the entity_attribute table.
     *
     * @var array<int, string>
     */
    private const array VALUE_COLUMNS = [
        'value_text',
        'value_integer',
        'value_float',
        'value_boolean',
        'value_date',
        'value_datetime',
    ];

    /**
     * Pre-built null template for all value columns.
     * Avoids calling array_fill_keys() on every blankRow() invocation.
     *
     * @var array<string, null>
     */
    private const array NULL_COLUMNS = [
        'value_text'     => null,
        'value_integer'  => null,
        'value_float'    => null,
        'value_boolean'  => null,
        'value_date'     => null,
        'value_datetime' => null,
    ];

    /**
     * Pending batch items grouped by entity type, then entity ID.
     *
     * @var array<string, array<int|string, Collection<int, Field>>>
     */
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
     * @param  Collection<int, Field>  $filled  Fields to persist.
     */
    public function persist(Collection $filled): void
    {
        if ($filled->isEmpty() || $this->entity === null) {
            return;
        }

        $this->persistGroup(
            $this->entity->getAttributeEntityType(),
            [$this->entity->id => $filled],
        );
    }

    /**
     * Persist a single field. Delegates to persist().
     *
     * @param  Field  $field  The field to persist.
     */
    public function saveField(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /**
     * Full-replace sync: persist the given fields and delete all entity_attribute rows
     * whose attribute_id is not in the filled set. Combines deleteExcluding + persist
     * in a single logical operation.
     *
     * @param  Collection<int, Field>  $filled  Fields that should remain (unfilled fields are excluded upstream).
     */
    public function syncFields(Collection $filled): void
    {
        $filledIds = $filled->map(fn (Field $f) => $f->attribute()->id)->values()->all();

        $this->deleteExcluding($filledIds);
        $this->persist($filled);
    }

    /**
     * Delete all entity_attribute rows whose attribute_id is NOT in the given list.
     * Used during sync to remove attributes no longer present on the entity.
     *
     * @param  array<int, int>  $attributeIds  Attribute IDs to keep.
     */
    public function deleteExcluding(array $attributeIds): void
    {
        $recordIds = $this->entityQuery()
            ->whereNotIn('attribute_id', $attributeIds)
            ->pluck('id')
            ->all();

        $this->delete($recordIds);
    }

    /**
     * Delete entity_attribute rows by attribute IDs (not record IDs).
     * Used during detach to remove specific attributes from an entity.
     *
     * @param  array<int, int>  $attributeIds  Attribute IDs whose rows should be removed.
     */
    public function detachByAttributeIds(array $attributeIds): void
    {
        $recordIds = $this->entityQuery()
            ->whereIn('attribute_id', $attributeIds)
            ->pluck('id')
            ->all();

        $this->delete($recordIds);
    }

    /**
     * Delete entity_attribute records and their translations by record IDs.
     *
     * @param  array<int, int>  $recordIds  entity_attribute primary key IDs to delete.
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
     * @param  Attributable           $entity  The entity whose attributes are being written.
     * @param  Collection<int, Field> $fields  Filled Field instances to persist.
     */
    public function add(Attributable $entity, Collection $fields): void
    {
        $this->items[$entity->getAttributeEntityType()][$entity->id] = $fields;
    }

    /**
     * Persist all staged items and clear the queue.
     *
     * Entities are grouped by entity_type so each group runs in ~7 queries.
     * In a typical product import all entities share the same type, so the
     * entire batch completes in ~7 queries total.
     */
    public function flush(): void
    {
        foreach ($this->items as $entityType => $entitiesByType) {
            $this->persistGroup($entityType, $entitiesByType);
        }

        $this->items = [];
    }

    /**
     * Core persistence logic shared by both single-entity and batch modes.
     *
     * Loads existing rows for the entire group in one query, partitions all
     * fields into update / insert / delete buckets, executes the minimal set
     * of batch DB operations, and syncs translations for all touched records.
     *
     * @param  string                                     $entityType      Morph-map key (e.g. 'product').
     * @param  array<int|string, Collection<int, Field>>  $entitiesByType  Fields keyed by entity ID.
     */
    private function persistGroup(string $entityType, array $entitiesByType): void
    {
        if (empty($entitiesByType)) {
            return;
        }

        $now = now();
        $entityIds = array_keys($entitiesByType);
        $attrIds = $this->collectAttrIds($entitiesByType);

        $existingByKey = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', $entityIds)
            ->whereIn('attribute_id', $attrIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($r) => self::attrKey($r->entity_id, $r->attribute_id));

        $existingIds = $existingByKey->flatten()->pluck('id')->all();
        $toUpdate = [];
        $toInsert = [];
        $toDelete = [];
        $insertTranslations = [];
        $updateTranslations = [];

        foreach ($entitiesByType as $entityId => $fields) {
            foreach ($fields as $field) {
                $this->partitionField(
                    $field, $entityType, $entityId, $existingByKey, $now,
                    $toUpdate, $toInsert, $toDelete, $insertTranslations, $updateTranslations,
                );
            }
        }

        if (! empty($toDelete)) {
            $this->delete($toDelete);
        }

        if (! empty($toUpdate)) {
            EavModels::query('entity_attribute')->upsert(
                $toUpdate,
                ['id'],
                [...self::VALUE_COLUMNS, 'updated_at'],
            );
        }

        if (! empty($toInsert)) {
            $updateTranslations += $this->insertAndResolveTranslations(
                $entityType,
                $toInsert,
                $insertTranslations,
                $existingIds,
            );
        }

        $this->syncTranslations($updateTranslations, $now);
    }

    /**
     * Partition a single field's storage items into update / insert / delete buckets.
     *
     * Called once per field inside the persistGroup() loop. All output arrays are
     * passed by reference so the caller accumulates results without extra allocations.
     *
     * @param  Field                                      $field
     * @param  string                                     $entityType
     * @param  int|string                                 $entityId
     * @param  Collection<string, Collection<int, mixed>> $existingByKey    Existing records keyed by attrKey().
     * @param  Carbon                                     $now
     * @param  array<int, array>                          $toUpdate         Accumulated rows to upsert.
     * @param  array<int, array>                          $toInsert         Accumulated rows to insert.
     * @param  array<int, int>                            $toDelete         Accumulated record IDs to delete.
     * @param  array<int, array>                          $insertTranslations Parallel to $toInsert.
     * @param  array<int, array>                          $updateTranslations Map of record_id => translations.
     */
    private function partitionField(
        Field $field,
        string $entityType,
        int|string $entityId,
        Collection $existingByKey,
        Carbon $now,
        array &$toUpdate,
        array &$toInsert,
        array &$toDelete,
        array &$insertTranslations,
        array &$updateTranslations,
    ): void {
        $attrId = $field->attribute()->id;
        $column = $field->column();
        $items = $field->toStorage();
        $itemCount = count($items);
        $existing = $existingByKey->get(self::attrKey($entityId, $attrId), collect())->values();

        foreach ($existing->take($itemCount) as $i => $record) {
            $row = $this->blankRow($entityType, $entityId, $attrId, $now);
            $row['id'] = $record->id;
            $row[$column] = $items[$i]['value'];
            $toUpdate[] = $row;

            if (! empty($items[$i]['translations'])) {
                $updateTranslations[$record->id] = $items[$i]['translations'];
            }
        }

        foreach (array_slice($items, $existing->count()) as $item) {
            $row = $this->blankRow($entityType, $entityId, $attrId, $now);
            $row[$column] = $item['value'];
            $toInsert[] = $row;
            $insertTranslations[] = $item['translations'];
        }

        foreach ($existing->slice($itemCount) as $record) {
            $toDelete[] = $record->id;
        }
    }

    /**
     * Batch-insert rows, SELECT back their auto-assigned IDs and align them
     * positionally to their translation payloads.
     *
     * To avoid picking up concurrent inserts for other entities, only rows
     * whose ID was not present before this persist call are considered.
     * $toInsert preserves per-(entity, attribute) insertion order, so records
     * returned in (entity_id, attribute_id, id) order map positionally to the
     * same order.
     *
     * @param  string             $entityType          Morph-map key of the entity type.
     * @param  array<int, array>  $toInsert            Rows to insert.
     * @param  array<int, array>  $insertTranslations  Translation payloads, parallel to $toInsert.
     * @param  array<int, int>    $existingIds         Record IDs that existed before this call.
     * @return array<int, array>                       Map of new record ID => translation payload.
     */
    private function insertAndResolveTranslations(
        string $entityType,
        array $toInsert,
        array $insertTranslations,
        array $existingIds,
    ): array {
        EavModels::query('entity_attribute')->insert($toInsert);

        $newEntityIds = array_unique(array_column($toInsert, 'entity_id'));
        $newAttrIds = array_unique(array_column($toInsert, 'attribute_id'));

        $newRecords = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', $newEntityIds)
            ->whereIn('attribute_id', $newAttrIds)
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderBy('entity_id')
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'attribute_id']);

        $idxByKey = [];
        foreach ($toInsert as $idx => $row) {
            $idxByKey[self::attrKey($row['entity_id'], $row['attribute_id'])][] = $idx;
        }

        $result = [];
        foreach ($newRecords->groupBy(fn ($r) => self::attrKey($r->entity_id, $r->attribute_id)) as $key => $records) {
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
     * Sync translations for all touched records in two queries:
     * one DELETE for stale locales, one UPSERT for current values.
     *
     * Handling all records together avoids a DELETE + UPSERT pair per record.
     *
     * @param  array<int, array<int, array{locale_id: int, value: mixed}>>  $translationsByRecordId
     * @param  Carbon                                                        $now
     */
    private function syncTranslations(array $translationsByRecordId, Carbon $now): void
    {
        if (empty($translationsByRecordId)) {
            return;
        }

        $recordIds = array_keys($translationsByRecordId);
        $rows = [];
        $validLocales = [];

        foreach ($translationsByRecordId as $recordId => $translations) {
            foreach ($translations as $t) {
                $validLocales[] = $t['locale_id'];
                $rows[] = [
                    'entity_type' => 'entity_attribute',
                    'entity_id'   => $recordId,
                    'locale_id'   => $t['locale_id'],
                    'label'       => $t['value'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        $validLocales = array_unique($validLocales);

        EavModels::query('entity_translation')
            ->where('entity_type', 'entity_attribute')
            ->whereIn('entity_id', $recordIds)
            ->when(! empty($validLocales), fn ($q) => $q->whereNotIn('locale_id', $validLocales))
            ->delete();

        if (! empty($rows)) {
            EavModels::query('entity_translation')->upsert(
                $rows,
                ['entity_type', 'entity_id', 'locale_id'],
                ['label', 'updated_at'],
            );
        }
    }

    /**
     * Collect all unique attribute IDs referenced across all entities in a group.
     *
     * Uses a plain array keyed by ID for O(1) deduplication instead of building
     * an intermediate Collection.
     *
     * @param  array<int|string, Collection<int, Field>>  $entitiesByType
     * @return array<int, int>
     */
    private function collectAttrIds(array $entitiesByType): array
    {
        $ids = [];
        foreach ($entitiesByType as $fields) {
            foreach ($fields as $field) {
                $ids[$field->attribute()->id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Return a Builder scoped to entity_attribute rows for the current entity.
     *
     * @return Builder
     */
    private function entityQuery(): Builder
    {
        return EavModels::query('entity_attribute')
            ->where('entity_type', $this->entity->getAttributeEntityType())
            ->where('entity_id', $this->entity->id);
    }

    /**
     * Build a row with all value columns set to null and all required metadata filled in.
     * The caller sets the single typed column it needs before adding the row to a batch.
     *
     * @param  string      $entityType  Morph-map key.
     * @param  int|string  $entityId    Primary key of the entity.
     * @param  int         $attrId      Primary key of the attribute.
     * @param  Carbon      $now         Timestamp applied to created_at and updated_at.
     * @return array<string, mixed>
     */
    private function blankRow(string $entityType, int|string $entityId, int $attrId, Carbon $now): array
    {
        return [
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'attribute_id' => $attrId,
            ...self::NULL_COLUMNS,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }

    /**
     * Build the composite lookup key used to group and retrieve records
     * by (entity_id, attribute_id) pair.
     *
     * @param int|string $entityId
     * @param int $attrId
     * @return string
     */
    private static function attrKey(int|string $entityId, int $attrId): string
    {
        return $entityId . ':' . $attrId;
    }
}
