<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Support\EavModels;

class GroupSchema extends BaseSchema
{
    public function find(int $id): AttributeGroup
    {
        /** @var AttributeGroup */
        return $this->query()->findOrFail($id);
    }

    /** Create a new attribute group, auto-positioned at the end. */
    public function create(array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        $data['sort'] ??= (int) $this->query()->max('sort') + 1;

        /** @var AttributeGroup $group */
        $group = $this->createRecord(fn () => $this->query()->create($data), $translations);

        Event::dispatch(new AttributeGroupCreated($group));

        return $group;
    }

    public function update(AttributeGroup $group, array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeGroup $group */
        $group = $this->updateRecord($group, $data, $translations);

        Event::dispatch(new AttributeGroupUpdated($group->fresh()));

        return $group;
    }

    public function delete(AttributeGroup $group): void
    {
        Event::dispatch(new AttributeGroupDeleted($this->deleteRecord($group)));
    }

    /**
     * Move a group to a new zero-based position.
     * Renumbers all groups' sort values.
     */
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

    /**
     * Assign attributes to a group. Unknown IDs are silently ignored by the DB query.
     *
     * @param  array<int>  $attributeIds
     */
    public function attach(AttributeGroup $group, array $attributeIds): void
    {
        EavModels::query('attribute')
            ->whereIn('id', $attributeIds)
            ->update(['attribute_group_id' => $group->id]);
    }

    protected function modelKey(): string
    {
        return 'attribute_group';
    }
}
