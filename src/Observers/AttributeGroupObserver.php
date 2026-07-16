<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Events\AttributeGroupCreated;
use Jurager\Eav\Events\AttributeGroupDeleted;
use Jurager\Eav\Events\AttributeGroupUpdated;
use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupObserver
{
    /** Handle the "created" event. */
    public function created(AttributeGroup $group): void
    {
        AttributeGroupCreated::dispatch($group);
    }

    /** Handle the "updated" event. */
    public function updated(AttributeGroup $group): void
    {
        AttributeGroupUpdated::dispatch($group);
    }

    /** Handle the "deleted" event. */
    public function deleted(AttributeGroup $group): void
    {
        AttributeGroupDeleted::dispatch($group);
    }
}
