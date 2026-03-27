<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Jobs\PruneAttribute;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\SchemaRegistry;

class AttributeObserver
{
    protected SchemaRegistry $schema;
    protected EnumRegistry $enums;

    public function __construct()
    {
        $this->schema = app(SchemaRegistry::class);
        $this->enums = app(EnumRegistry::class);
    }

    /**
     * Re-index entities when an attribute's searchable flag changes.
     * Flush the schema cache so long-running processes pick up the new definition.
     */
    public function updated(Attribute $attribute): void
    {
        $this->schema->flush($attribute->entity_type);

        if ($attribute->wasChanged('searchable')) {
            $this->syncSearchable($attribute);
        }
    }

    /**
     * Re-index entities when an attribute is soft-deleted, then schedule pruning.
     */
    public function deleted(Attribute $attribute): void
    {
        $this->schema->flush($attribute->entity_type);

        // It only makes sense to link if he participated in the search at all
        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }
    }

    /**
     * Permanently remove the attribute and its data after re-indexing completes.
     * Dispatched when the attribute model is force-deleted, or by SyncSearchable
     * after re-indexing a trashed attribute.
     */
    public function forceDeleted(Attribute $attribute): void
    {
        $this->schema->flush($attribute->entity_type);
        $this->enums->flush($attribute->id);

        PruneAttribute::dispatch($attribute->id);
    }

    /**
     * @param Attribute $attribute
     * @return void
     */
    protected function syncSearchable(Attribute $attribute): void
    {
        SyncSearchable::dispatch($attribute->entity_type, $attribute->id)->afterCommit();
    }
}
