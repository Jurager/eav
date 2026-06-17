<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupObserver
{
    public function created(AttributeGroup $group): void
    {
        AttributeGroupCreated::dispatch($group);
    }

    public function updated(AttributeGroup $group): void
    {
        AttributeGroupUpdated::dispatch($group);
    }

    public function deleted(AttributeGroup $group): void
    {
        AttributeGroupDeleted::dispatch($group);
    }
}
