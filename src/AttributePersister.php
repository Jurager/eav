<?php

namespace Jurager\Eav;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;

/**
 * Handles low-level persistence of attribute field values and translations.
 */
class AttributePersister
{
    public function __construct(
        private readonly Attributable $entity
    ) {
    }

    /**
     * Persist filled fields to the database.
     */
    public function persist(Collection $filled): void
    {
        if ($filled->isEmpty()) {
            return;
        }

        $attributeIds = $filled->map(fn (Field $f) => $f->getAttribute()->id)->values()->all();

        $existing = $this->entityQuery()
            ->whereIn('attribute_id', $attributeIds)
            ->orderBy('id')
            ->get()
            ->groupBy('attribute_id');

        $filled->each(fn (Field $field) => $this->syncField(
            $field,
            $existing->get($field->getAttribute()->id, collect())
        ));
    }

    /**
     * Sync a single field to the database (load existing records and upsert).
     */
    public function saveField(Field $field): void
    {
        $existing = $this->entityQuery()
            ->where('attribute_id', $field->getAttribute()->id)
            ->orderBy('id')
            ->get();

        $this->syncField($field, $existing);
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
     * Synchronize one field against existing records (update, insert, delete overflow).
     *
     * @param  Collection<int, Model>  $existing
     */
    protected function syncField(Field $field, Collection $existing): void
    {
        $items = $field->toStorage();
        $column = $field->getStorageColumn();
        $now = now();
        $toUpdate = $existing->take(count($items));
        $toInsert = array_slice($items, $existing->count());
        $toDelete = $existing->skip(count($items));

        // Update existing records that map to incoming items.
        $toUpdate->each(function ($record, int $i) use ($items, $column, $now) {
            $record->update([$column => $items[$i]['value'], 'updated_at' => $now]);
            $this->syncTranslations($record->id, $items[$i]['translations']);
        });

        // Insert new records beyond the existing count.
        foreach ($toInsert as $item) {
            $id = EavModels::query('entity_attribute')->insertGetId([
                'entity_type' => $this->entity->getAttributeEntityType(),
                'entity_id' => $this->entity->id,
                'attribute_id' => $field->getAttribute()->id,
                $column => $item['value'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncTranslations($id, $item['translations']);
        }

        // Delete surplus existing records.
        if ($toDelete->isNotEmpty()) {
            $this->delete($toDelete->pluck('id')->all());
        }
    }

    /**
     * Synchronize translation rows for a single entity attribute record.
     *
     * @param  array<int, array{locale_id: int, value: mixed}>  $translations
     */
    protected function syncTranslations(int $recordId, array $translations): void
    {
        $newLocaleIds = array_column($translations, 'locale_id');

        // Remove locales that are no longer present.
        EavModels::query('entity_translation')
            ->where('entity_type', 'entity_attribute')
            ->where('entity_id', $recordId)
            ->when($newLocaleIds, fn ($q) => $q->whereNotIn('locale_id', $newLocaleIds))
            ->delete();

        if (empty($translations)) {
            return;
        }

        $now = now();

        $rows = array_map(fn ($t) => [
            'entity_type' => 'entity_attribute',
            'entity_id' => $recordId,
            'locale_id' => $t['locale_id'],
            'label' => $t['value'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $translations);

        EavModels::query('entity_translation')->upsert(
            $rows,
            ['entity_type', 'entity_id', 'locale_id'],
            ['label', 'updated_at']
        );
    }

    /**
     * Build base query for entity attribute records.
     */
    protected function entityQuery(): Builder
    {
        return EavModels::query('entity_attribute')
            ->where('entity_type', $this->entity->getAttributeEntityType())
            ->where('entity_id', $this->entity->id);
    }
}
