<?php

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use LogicException;

/**
 * Handles persistence of EAV attribute values and their translations.
 *
 * Two modes:
 *   Single-entity — construct with $entity, call persist() / save() / replace().
 *   Batch         — construct without arguments, stage with add(), execute with flush().
 */
class AttributePersister
{
    /** PDO bind parameter limit — applies to both MySQL and PostgreSQL. */
    private const int BIND_LIMIT = 65535;

    private const array VALUE_COLUMNS = [
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    /** @var array<string, array<int|string, Collection<int, Field>>> */
    private array $items = [];

    /** @param  Attributable|null  $entity  Omit for batch mode. */
    public function __construct(
        private readonly ?Attributable $entity = null,
    ) {
    }

    /**
     * Persist filled fields for the current entity.
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

    /** Save a single field. */
    public function save(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /**
     * Persist fields and delete all existing rows not in this set.
     *
     * @param  Collection<int, Field>  $filled
     */
    public function replace(Collection $filled): void
    {
        $this->deleteExcluding($filled->map(fn (Field $f) => $f->attribute()->id)->values()->all());
        $this->persist($filled);
    }

    /**
     * Delete entity_attribute rows for the current entity not matching the given attribute IDs.
     *
     * @param  array<int>  $attributeIds  Attribute IDs to keep.
     */
    public function deleteExcluding(array $attributeIds): void
    {
        $this->delete(
            $this->entityQuery()->whereNotIn('attribute_id', $attributeIds)->pluck('id')->all(),
        );
    }

    /**
     * Delete entity_attribute rows for the current entity matching the given attribute IDs.
     *
     * @param  array<int>  $attributeIds
     */
    public function detach(array $attributeIds): void
    {
        $this->delete(
            $this->entityQuery()->whereIn('attribute_id', $attributeIds)->pluck('id')->all(),
        );
    }

    /**
     * Delete entity_attribute records and their translations by record IDs.
     *
     * @param  array<int>  $recordIds
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
     * Stage fields for an entity. Call flush() when the batch is complete.
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

    /** Persist all staged items and clear the queue. */
    public function flush(): void
    {
        foreach ($this->items as $entityType => $entitiesByType) {
            $this->persistGroup($entityType, $entitiesByType);
        }

        $this->items = [];
    }

    /**
     * Persist a group of fields for a single entity type, wrapped in a transaction.
     *
     * @param  array<int|string, Collection<int, Field>>  $entitiesByType
     */
    private function persistGroup(string $entityType, array $entitiesByType): void
    {
        if (empty($entitiesByType)) {
            return;
        }

        DB::transaction(function () use ($entityType, $entitiesByType): void {
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

            // Load all existing rows for this group in a single query.
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

            $this->eachChunk($toUpdate, fn ($c) => EavModels::query('entity_attribute')
                ->upsert($c, ['id'], [...self::VALUE_COLUMNS, 'updated_at']));

            if (! empty($toInsert)) {
                $this->eachChunk($toInsert, fn ($c) => EavModels::query('entity_attribute')->insert($c));

                $localizableRows = array_filter($insertTranslations);
                if (! empty($localizableRows)) {
                    $newRecords = $this->fetchInsertedRecords($entityType, $toInsert, $existingIds);
                    $rows = $this->buildTranslationRows($this->alignTranslations($toInsert, $newRecords, $insertTranslations), $now);
                    $this->eachChunk($rows, fn ($c) => EavModels::query('entity_translation')->insert($c));
                }
            }

            // Sync translations for updated rows: delete old, insert new.
            if (! empty($updateTranslations)) {
                EavModels::query('entity_translation')
                    ->where('entity_type', 'entity_attribute')
                    ->whereIn('entity_id', array_keys($updateTranslations))
                    ->delete();

                $this->eachChunk(
                    $this->buildTranslationRows($updateTranslations, $now),
                    fn ($c) => EavModels::query('entity_translation')->insert($c),
                );
            }
        });
    }

    /**
     * Partition fields into update / insert / delete / translation buckets.
     *
     * Localizable fields keep the value column null; values go to entity_translations.
     * Non-localizable fields write directly to the typed value column.
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
                        // An empty array causes the sync step to delete all existing translations for this record.
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
     * Fetch newly inserted entity_attribute rows ordered to align with $insertedRows.
     *
     * @param  array<int, array>  $insertedRows
     * @param  array<int>  $existingIds  IDs that existed before this batch.
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
     * @return array<int, array<int, array>>
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
     * Build flat entity_translations rows from a record → translations map.
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
     * Invoke $callback for each bind-safe chunk of rows.
     *
     * Chunk size = BIND_LIMIT / column count — safe for both MySQL and PostgreSQL.
     */
    private function eachChunk(array $rows, callable $callback): void
    {
        if (empty($rows)) {
            return;
        }

        $chunkSize = max(1, intdiv(self::BIND_LIMIT, max(1, count($rows[0]))));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Build a blank entity_attribute row with all value columns null.
     *
     * @return array<string, mixed>
     */
    private function blankRow(string $entityType, int|string $entityId, int $attrId, Carbon $now): array
    {
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'attribute_id' => $attrId,
            ...array_fill_keys(self::VALUE_COLUMNS, null),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Return a Builder scoped to current entity's attribute rows. */
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
