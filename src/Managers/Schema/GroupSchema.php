<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Support\EavModels;

class GroupSchema extends BaseSchema
{
    public function find(int $id): AttributeGroup
    {
        return EavModels::query('attribute_group')->findOrFail($id);
    }

    /** Create a new attribute group, auto-positioned at the end. */
    public function create(array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        $data['sort'] = $this->nextGroupSort();

        $group = EavModels::query('attribute_group')->create($data);

        $this->saveTranslations($group, $translations);

        Event::dispatch(new AttributeGroupCreated($group));

        return $group;
    }

    public function update(AttributeGroup $group, array $data): AttributeGroup
    {
        $translations = $this->extractTranslations($data);

        $group->update($data);

        $this->saveTranslations($group, $translations);

        Event::dispatch(new AttributeGroupUpdated($group->fresh()));

        return $group;
    }

    public function delete(AttributeGroup $group): void
    {
        $snapshot = clone $group;

        $group->delete();

        Event::dispatch(new AttributeGroupDeleted($snapshot));
    }

    /**
     * Move a group to a new zero-based position.
     * Renumbers all groups' sort values.
     */
    public function sort(AttributeGroup $group, int $position): AttributeGroup
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
    public function attach(AttributeGroup $group, array $attributeIds): void
    {
        EavModels::query('attribute')
            ->whereIn('id', $attributeIds)
            ->update(['attribute_group_id' => $group->id]);
    }

    private function nextGroupSort(): int
    {
        return (int) EavModels::query('attribute_group')->max('sort') + 1;
    }
}
