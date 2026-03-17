<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;

class AttributeObserver
{
    public function updated(Attribute $attribute): void
    {
        if (! $attribute->wasChanged('searchable')) {
            return;
        }

        SyncSearchable::dispatch($attribute->entity_type, $attribute->id);
    }

    public function deleted(Attribute $attribute): void
    {
        SyncSearchable::dispatch($attribute->entity_type, $attribute->id);
    }
}
