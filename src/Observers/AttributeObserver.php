<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Jobs\PruneAttribute;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\SchemaRegistry;

class AttributeObserver
{
    public function __construct(
        protected SchemaRegistry $schema,
        protected EnumRegistry $enums,
    ) {
    }

    /**
     * Forget the schema cache when a new attribute is created.
     */
    public function created(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);
    }

    /**
     * Re-index entities when an attribute's searchable flag changes.
     */
    public function updated(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);

        if ($attribute->wasChanged('searchable')) {
            $this->syncSearchable($attribute);
        }
    }

    /**
     * Re-index entities when an attribute is soft-deleted, then schedule pruning.
     */
    public function deleted(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);

        // It only makes sense to link if participated in the search at all
        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }
    }

    /**
     * Permanently remove the attribute and its data after re-indexing completes.
     */
    public function forceDeleted(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);
        $this->enums->forget($attribute->id);

        PruneAttribute::dispatch($attribute->id);
    }

    /**
     * Forget the schema cache when a soft-deleted attribute is restored.
     */
    public function restored(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);

        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }
    }

    protected function syncSearchable(Attribute $attribute): void
    {
        SyncSearchable::dispatch($attribute->entity_type, $attribute->id)->afterCommit();
    }
}
