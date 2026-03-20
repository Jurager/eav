<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Fields\SelectField;
use Jurager\Eav\Jobs\PruneAttribute;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;

class AttributeObserver
{
    /**
     * Re-index entities when an attribute's searchable flag changes.
     */
    public function updated(Attribute $attribute): void
    {
        if ($attribute->wasChanged('searchable')) {
            SyncSearchable::dispatch($attribute->entity_type, $attribute->id);
        }
    }

    /**
     * Re-index entities when an attribute is soft-deleted, then schedule pruning.
     */
    public function deleted(Attribute $attribute): void
    {
        SyncSearchable::dispatch($attribute->entity_type, $attribute->id);
    }

    /**
     * Permanently remove the attribute and its data after re-indexing completes.
     * Dispatched when the attribute model is force-deleted, or by SyncSearchable
     * after re-indexing a trashed attribute.
     */
    public function forceDeleted(Attribute $attribute): void
    {
        SelectField::flushEnumCache($attribute->id);
        PruneAttribute::dispatch($attribute->id);
    }
}
