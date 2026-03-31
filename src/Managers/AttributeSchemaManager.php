<?php

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

/**
 * Manages the EAV attribute schema: attributes, groups, and enums.
 *
 * Responsible for create/read/update/delete/sort operations on attribute definitions.
 * For reading and writing attribute *values* on entities, use AttributeManager.
 */
class AttributeSchemaManager
{
    public function __construct(
        protected TranslationManager $translations,
    ) {
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function getAttributes(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute');

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function getEnums(Attribute $attribute, ?callable $modifier = null): mixed
    {
        $query = $attribute->enums()->getQuery();

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function getTypes(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute_type');

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function getGroups(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute_group');

        return $modifier ? $modifier($query) : $query->get();
    }

    public function getAttribute(int $id): Attribute
    {
        return EavModels::query('attribute')->findOrFail($id);
    }

    public function getGroup(int $id): AttributeGroup
    {
        return EavModels::query('attribute_group')->findOrFail($id);
    }

    public function getEnum(int $id): AttributeEnum
    {
        return EavModels::query('attribute_enum')->findOrFail($id);
    }

    public function getType(int $id): AttributeType
    {
        return EavModels::query('attribute_type')->findOrFail($id);
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

        return $this->createAttribute($data);
    }

    /** Create a new attribute, applying type constraints and auto-positioning. */
    public function createAttribute(array $data): Attribute
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
    public function updateAttribute(Attribute $attribute, array $data): Attribute
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

    public function deleteAttribute(Attribute $attribute): void
    {
        $snapshot = clone $attribute;

        $attribute->delete();

        Event::dispatch(new AttributeDeleted($snapshot));
    }

    /**
     * Move an attribute to a new zero-based position within its group.
     * Renumbers all siblings' sort values.
     */
    public function sortAttribute(Attribute $attribute, int $position): Attribute
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

    /** Create a new attribute group, auto-positioned at the end. */
    public function createGroup(array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        $data['sort'] = $this->nextGroupSort();

        $group = EavModels::query('attribute_group')->create($data);

        $this->saveTranslations($group, $translations);

        Event::dispatch(new AttributeGroupCreated($group));

        return $group;
    }

    public function updateGroup(AttributeGroup $group, array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        $group->update($data);

        $this->saveTranslations($group, $translations);

        Event::dispatch(new AttributeGroupUpdated($group->fresh()));

        return $group;
    }

    public function deleteGroup(AttributeGroup $group): void
    {
        $snapshot = clone $group;

        $group->delete();

        Event::dispatch(new AttributeGroupDeleted($snapshot));
    }

    /**
     * Move a group to a new zero-based position.
     * Renumbers all groups' sort values.
     */
    public function sortGroup(AttributeGroup $group, int $position): AttributeGroup
    {
        $all = EavModels::query('attribute_group')
            ->withoutGlobalScope('ordered')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $reordered = $this->reorder($all, $group->id, $position);

        $reordered->each(function (AttributeGroup $item, int $index): void {
            $item->sort = $index;
            $item->saveQuietly();
        });

        return $group->fresh();
    }

    /**
     * Assign attributes to a group. Unknown IDs are silently ignored by the DB query.
     *
     * @param  array<int>  $attributeIds
     */
    public function attachAttributesToGroup(AttributeGroup $group, array $attributeIds): void
    {
        EavModels::query('attribute')
            ->whereIn('id', $attributeIds)
            ->update(['attribute_group_id' => $group->id]);
    }

    public function createEnum(Attribute $attribute, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        $enum = $attribute->enums()->create($data);

        $this->saveTranslations($enum, $translations);

        Event::dispatch(new AttributeEnumCreated($enum));

        return $enum;
    }

    public function updateEnum(AttributeEnum $enum, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        $enum->update($data);

        $this->saveTranslations($enum, $translations);

        Event::dispatch(new AttributeEnumUpdated($enum->fresh()));

        return $enum;
    }

    public function deleteEnum(AttributeEnum $enum): void
    {
        $snapshot = clone $enum;

        $enum->delete();

        Event::dispatch(new AttributeEnumDeleted($snapshot));
    }

    /**
     * Initiate a full-text search on attributes via Laravel Scout.
     *
     * @param  callable(mixed): mixed|null  $modifier
     * @throws SearchNotAvailableException
     */
    public function searchAttributes(string $query, ?callable $modifier = null): mixed
    {
        $modelClass = EavModels::class('attribute');

        if (! method_exists($modelClass, 'search')) {
            throw SearchNotAvailableException::scoutNotInstalled();
        }

        $builder = $modelClass::search($query);

        return $modifier ? $modifier($builder) : $builder;
    }

    /** @return array<int, array<string, mixed>> */
    private function extractTranslations(array &$data): array
    {
        $translations = $data['translations'] ?? [];
        unset($data['translations']);

        return $translations;
    }

    /** @param  array<int, array<string, mixed>>  $translations */
    private function saveTranslations(Model $model, array $translations): void
    {
        if (! empty($translations)) {
            $this->translations->save($model, $translations);
        }
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

    private function nextGroupSort(): int
    {
        return (int) EavModels::query('attribute_group')->max('sort') + 1;
    }

    /**
     * @param  Collection<int, Attribute|AttributeGroup>  $items
     * @return Collection<int, Attribute|AttributeGroup>
     */
    private function reorder(Collection $items, int $id, int $targetIndex): Collection
    {
        $list = $items->values();

        $currentIndex = $list->search(fn ($item) => $item->id === $id);

        if ($currentIndex === false) {
            return $list;
        }

        $item = $list->splice($currentIndex, 1)->first();

        $targetIndex = max(0, min($targetIndex, $list->count()));

        $list->splice($targetIndex, 0, [$item]);

        return $list->values();
    }
}
