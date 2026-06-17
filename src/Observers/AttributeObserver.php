<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted as AttributeDeletedEvent;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Jobs\PruneAttribute;
use Jurager\Eav\Jobs\SyncFilterable;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Support\EavModels;

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

        if ($attribute->filterable) {
            $this->syncFilterable($attribute);
        }

        AttributeCreated::dispatch($attribute);
    }

    /**
     * Re-index entities and sync filterable settings when searchable/filterable flags change.
     */
    public function updated(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);

        if ($attribute->wasChanged('searchable')) {
            $this->syncSearchable($attribute);
        }

        if ($attribute->wasChanged('filterable')) {
            $this->syncFilterable($attribute);
            $this->syncSearchable($attribute);
        }

        AttributeUpdated::dispatch($attribute);
    }

    /**
     * Re-index and clean up values when an attribute is deleted.
     */
    public function deleted(Attribute $attribute): void
    {
        if ($attribute->isForceDeleting()) {
            return;
        }

        $this->schema->forget($attribute->entity_type);

        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }

        if ($attribute->filterable) {
            $this->syncFilterable($attribute);
        }

        EavModels::query('entity_attribute')
            ->where('attribute_id', $attribute->id)
            ->delete();

        AttributeDeletedEvent::dispatch($attribute);
    }

    /**
     * Flush caches, update indexes, and prune stored values on permanent deletion.
     */
    public function forceDeleted(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);
        $this->enums->forget($attribute->id);

        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }

        if ($attribute->filterable) {
            $this->syncFilterable($attribute);
        }

        PruneAttribute::dispatch($attribute->id);

        AttributeDeletedEvent::dispatch($attribute);
    }

    /**
     * Re-index and sync filterable settings when a soft-deleted attribute is restored.
     */
    public function restored(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);

        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }

        if ($attribute->filterable) {
            $this->syncFilterable($attribute);
        }
    }

    protected function syncSearchable(Attribute $attribute): void
    {
        SyncSearchable::dispatch($attribute->entity_type, $attribute->id)->afterCommit();
    }

    protected function syncFilterable(Attribute $attribute): void
    {
        SyncFilterable::dispatch($attribute->entity_type)->afterCommit();
    }
}
