<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeGroup;

class GroupSchema extends BaseSchema
{
    /** Find an attribute group by ID. */
    public function find(int $id): AttributeGroup
    {
        /** @var AttributeGroup */
        return $this->query()->findOrFail($id);
    }

    /** Create a new attribute group. */
    public function create(array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);
        $data['sort'] ??= (int) $this->query()->max('sort') + 1;

        /** @var AttributeGroup $group */
        $group = $this->createRecord(fn () => $this->query()->create($data), $translations);

        Event::dispatch(new AttributeGroupCreated($group));

        return $group;
    }

    /** Update an existing attribute group. */
    public function update(AttributeGroup $group, array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeGroup $group */
        $group = $this->updateRecord($group, $data, $translations);

        Event::dispatch(new AttributeGroupUpdated($group->fresh()));

        return $group;
    }

    /** Delete an attribute group. */
    public function delete(AttributeGroup $group): void
    {
        Event::dispatch(new AttributeGroupDeleted($this->deleteRecord($group)));
    }

    /** Sort a group within the collection. */
    public function sort(AttributeGroup $group, int $position): AttributeGroup
    {
        $all = $this->query()
            ->withoutGlobalScope('ordered')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $this->applySort($this->reorder($all, $group->id, $position));

        return $group->fresh();
    }

    /** Assign attributes to a group. */
    public function attach(AttributeGroup $group, array $attributeIds): void
    {
        Eav::$attributeModel::query()
            ->whereIn('id', $attributeIds)
            ->update(['attribute_group_id' => $group->id]);
    }

    /** Get the model class. */
    protected function modelClass(): string
    {
        return Eav::$attributeGroupModel;
    }
}
