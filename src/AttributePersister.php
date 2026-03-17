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
 * Uses bulk operations to minimise query count:
 *   - One SELECT to load existing records per persist() call.
 *   - One upsert (by id) for all updates.
 *   - One INSERT for all new records, then one SELECT to retrieve their IDs.
 *   - One DELETE + one UPSERT to sync all translations across every touched record.
 *
 * Query budget per entity: ~6 queries regardless of how many attributes are saved
 * (previously: 2–3 queries per attribute).
 */
class AttributePersister
{
    /**
     * All value storage columns in the entity_attribute table.
     */
    private const VALUE_COLUMNS = [
        'value_text',
        'value_integer',
        'value_float',
        'value_boolean',
        'value_date',
        'value_datetime',
    ];

    public function __construct(
        private readonly Attributable $entity
    ) {}

    /**
     * Persist a collection of filled fields using bulk DB operations.
     *
     * Flow:
     *   1. Load all existing entity_attribute rows for the given attributes (1 query).
     *   2. Partition into update / insert / delete buckets.
     *   3. Bulk-update existing rows via upsert-by-id (1 query).
     *   4. Bulk-insert new rows, then retrieve their IDs (2 queries).
     *   5. Delete surplus rows (1 query, conditional).
     *   6. Sync all translations in two queries: 1 DELETE stale + 1 UPSERT.
     *
     * @param  Collection<int, Field>  $filled
     */
    public function persist(Collection $filled): void
    {
        if ($filled->isEmpty()) {
            return;
        }

        $now         = now();
        $entityType  = $this->entity->getAttributeEntityType();
        $entityId    = $this->entity->id;
        $attributeIds = $filled->map(fn (Field $f) => $f->getAttribute()->id)->values()->all();

        // ── 1. Load all existing rows in one query ────────────────────────────────
        $existingByAttr = $this->entityQuery()
            ->whereIn('attribute_id', $attributeIds)
            ->orderBy('id')
            ->get()
            ->groupBy('attribute_id');

        $toUpdate           = [];   // rows to upsert by id
        $toInsert           = [];   // rows to bulk-insert
        $toDelete           = [];   // record IDs to remove
        $insertTranslations = [];   // aligned with $toInsert by index
        $updateTranslations = [];   // [record_id => translations[]]

        foreach ($filled as $field) {
            $attrId   = $field->getAttribute()->id;
            $column   = $field->getStorageColumn();
            $items    = $field->toStorage();
            $existing = $existingByAttr->get($attrId, collect())->values();

            // ── UPDATE: pair incoming items with existing rows ────────────────────
            foreach ($existing->take(count($items)) as $i => $record) {
                $row              = $this->blankRow($entityType, $entityId, $attrId, $now);
                $row['id']        = $record->id;
                $row[$column]     = $items[$i]['value'];
                $toUpdate[]       = $row;

                if (! empty($items[$i]['translations'])) {
                    $updateTranslations[$record->id] = $items[$i]['translations'];
                }
            }

            // ── INSERT: items beyond the existing count ───────────────────────────
            foreach (array_slice($items, $existing->count()) as $item) {
                $row          = $this->blankRow($entityType, $entityId, $attrId, $now);
                $row[$column] = $item['value'];
                $toInsert[]             = $row;
                $insertTranslations[]   = $item['translations'];
            }

            // ── DELETE: surplus existing rows ─────────────────────────────────────
            foreach ($existing->slice(count($items)) as $record) {
                $toDelete[] = $record->id;
            }
        }

        // ── 2. Bulk DELETE surplus rows ───────────────────────────────────────────
        if (! empty($toDelete)) {
            $this->delete($toDelete);
        }

        // ── 3. Bulk UPDATE via upsert-by-id ───────────────────────────────────────
        // The INSERT attempt in ON DUPLICATE KEY UPDATE will conflict on the primary
        // key, triggering the UPDATE path. All required NOT NULL columns are provided
        // in blankRow() to satisfy the INSERT side.
        if (! empty($toUpdate)) {
            EavModels::query('entity_attribute')->upsert(
                $toUpdate,
                ['id'],
                [...self::VALUE_COLUMNS, 'updated_at'],
            );
        }

        // ── 4. Bulk INSERT + retrieve IDs ─────────────────────────────────────────
        if (! empty($toInsert)) {
            EavModels::query('entity_attribute')->insert($toInsert);

            // Retrieve only the rows we just inserted: exclude every ID that existed
            // before this persist() call so concurrent inserts for other entities
            // are never picked up.
            $existingIds    = $existingByAttr->flatten()->pluck('id')->all();
            $newAttrIds     = array_unique(array_column($toInsert, 'attribute_id'));

            $newRecords = $this->entityQuery()
                ->whereIn('attribute_id', $newAttrIds)
                ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
                ->orderBy('attribute_id')
                ->orderBy('id')
                ->get(['id', 'attribute_id']);

            // Re-align each new record to its insertTranslations entry.
            // $toInsert preserves per-attribute insertion order, so records returned
            // in (attribute_id, id) order map positionally to the same order.
            $insertIdxByAttr = [];
            foreach ($toInsert as $idx => $row) {
                $insertIdxByAttr[$row['attribute_id']][] = $idx;
            }

            foreach ($newRecords->groupBy('attribute_id') as $attrId => $records) {
                foreach ($records->values() as $pos => $record) {
                    $idx = $insertIdxByAttr[$attrId][$pos] ?? null;
                    if ($idx !== null && ! empty($insertTranslations[$idx])) {
                        $updateTranslations[$record->id] = $insertTranslations[$idx];
                    }
                }
            }
        }

        // ── 5. Bulk sync translations (1 DELETE + 1 UPSERT) ──────────────────────
        $this->bulkSyncTranslations($updateTranslations, $now);
    }

    /**
     * Persist a single field. Delegates to persist() to reuse the bulk machinery.
     */
    public function saveField(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /**
     * Delete entity attribute records (and their translations) by record IDs.
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
     * Delete all entity attribute records whose attribute_id is NOT in the given list.
     * Used during sync to remove attributes no longer present.
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
     * Delete entity attribute records by attribute IDs (not record IDs).
     * Used during detach.
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
     * Return a base Builder scoped to entity_attribute rows for the current entity.
     */
    protected function entityQuery(): Builder
    {
        return EavModels::query('entity_attribute')
            ->where('entity_type', $this->entity->getAttributeEntityType())
            ->where('entity_id', $this->entity->id);
    }

    /**
     * Sync all translations for a batch of entity_attribute records in two queries:
     * one DELETE for removed locales, one UPSERT for new/updated values.
     *
     * All records are handled together to avoid one DELETE+UPSERT pair per record.
     *
     * @param  array<int, array<int, array{locale_id: int, value: mixed}>>  $translationsByRecordId
     */
    private function bulkSyncTranslations(array $translationsByRecordId, Carbon $now): void
    {
        if (empty($translationsByRecordId)) {
            return;
        }

        $recordIds    = array_keys($translationsByRecordId);
        $rows         = [];
        $validLocales = [];

        foreach ($translationsByRecordId as $recordId => $translations) {
            foreach ($translations as $t) {
                $validLocales[] = $t['locale_id'];
                $rows[]         = [
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

        // Remove stale locales across all touched records in a single query.
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
     * Build a row with all value columns set to null and all required metadata filled in.
     * The caller sets the single value column it needs before adding to the batch.
     */
    private function blankRow(string $entityType, int|string $entityId, int $attrId, Carbon $now): array
    {
        return [
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'attribute_id' => $attrId,
            ...array_fill_keys(self::VALUE_COLUMNS, null),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }
}
