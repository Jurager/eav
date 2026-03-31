<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

class AttributeSchema extends BaseSchema
{
    public function find(int $id): Attribute
    {
        return EavModels::query('attribute')->findOrFail($id);
    }

    /**
     * Find an existing attribute by entity type and code, or create it.
     * For existing attributes, only translations are updated — other fields are not overwritten.
     */
    public function findOrCreate(string $entityType, string $code, array $data): Attribute
    {
        $attribute = EavModels::query('attribute')
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->first();

        if ($attribute) {
            $translations = $data['translations'] ?? [];

            if (! empty($translations)) {
                $this->translations->save($attribute, $translations);
            }

            return $attribute;
        }

        return $this->create($data);
    }

    /** Create a new attribute, applying type constraints and auto-positioning. */
    public function create(array $data): Attribute
    {
        $translations = $this->extractTranslations($data);

        $type = EavModels::query('attribute_type')->findOrFail($data['attribute_type_id']);

        $data = $this->applyTypeConstraints($data, $type);
        $data = $this->applyAutoSort($data);

        $attribute = EavModels::query('attribute')->create($data);

        $this->saveTranslations($attribute, $translations);

        Event::dispatch(new AttributeCreated($attribute));

        return $attribute;
    }

    /** Update an existing attribute, re-applying type constraints. */
    public function update(Attribute $attribute, array $data): Attribute
    {
        $translations = $this->extractTranslations($data);

        $typeId = $data['attribute_type_id'] ?? $attribute->attribute_type_id;
        $type   = EavModels::query('attribute_type')->findOrFail($typeId);

        $data = $this->applyTypeConstraints($data, $type);

        $attribute->update($data);

        $this->saveTranslations($attribute, $translations);

        Event::dispatch(new AttributeUpdated($attribute->fresh()));

        return $attribute;
    }

    public function delete(Attribute $attribute): void
    {
        $snapshot = clone $attribute;

        $attribute->delete();

        Event::dispatch(new AttributeDeleted($snapshot));
    }

    /**
     * Move an attribute to a new zero-based position within its group.
     * Renumbers all siblings' sort values.
     */
    public function sort(Attribute $attribute, int $position): Attribute
    {
        $siblings = EavModels::query('attribute')
            ->withoutGlobalScope('ordered')
            ->where('attribute_group_id', $attribute->attribute_group_id)
            ->where('entity_type', $attribute->entity_type)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $reordered = $this->reorder($siblings, $attribute->id, $position);

        $reordered->each(function (Attribute $item, int $index): void {
            $item->sort = $index;
            $item->saveQuietly();
        });

        return $attribute->fresh();
    }

    private function applyTypeConstraints(array $data, AttributeType $type): array
    {
        foreach (['localizable', 'multiple', 'unique', 'filterable', 'searchable'] as $flag) {
            if (array_key_exists($flag, $data) && ! $type->{$flag}) {
                $data[$flag] = false;
            }
        }

        return $data;
    }

    private function applyAutoSort(array $data): array
    {
        if (isset($data['sort'])) {
            return $data;
        }

        $groupId = $data['attribute_group_id'] ?? null;

        $data['sort'] = (int) EavModels::query('attribute')
            ->when($groupId, fn ($q) => $q->where('attribute_group_id', $groupId))
            ->unless($groupId, fn ($q) => $q->whereNull('attribute_group_id'))
            ->max('sort') + 1;

        return $data;
    }
}
