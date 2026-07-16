<?php

declare(strict_types=1);

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
use Jurager\Eav\Eav;

class AttributeObserver
{
    public function __construct(
        protected SchemaRegistry $schema,
        protected EnumRegistry $enums,
    ) {
    }

    /** Handle the "created" event. */
    public function created(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);
        $this->syncAttributeStates($attribute);

        AttributeCreated::dispatch($attribute);
    }

    /** Handle the "updated" event. */
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

    /** Handle the "deleted" event. */
    public function deleted(Attribute $attribute): void
    {
        if ($attribute->isForceDeleting()) {
            return;
        }

        $this->schema->forget($attribute->entity_type);
        $this->syncAttributeStates($attribute);

        Eav::$entityAttributeModel::query()
            ->where('attribute_id', $attribute->id)
            ->delete();

        AttributeDeletedEvent::dispatch($attribute);
    }

    /** Handle the "forceDeleted" event. */
    public function forceDeleted(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);
        $this->enums->forget($attribute->id);

        $this->syncAttributeStates($attribute);
        PruneAttribute::dispatch($attribute->id);

        AttributeDeletedEvent::dispatch($attribute);
    }

    /** Handle the "restored" event. */
    public function restored(Attribute $attribute): void
    {
        $this->schema->forget($attribute->entity_type);
        $this->syncAttributeStates($attribute);
    }

    /** Sync all relevant attribute states (searchable/filterable). */
    protected function syncAttributeStates(Attribute $attribute): void
    {
        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }

        if ($attribute->filterable) {
            $this->syncFilterable($attribute);
        }
    }

    /** Dispatch the job to sync searchable index. */
    protected function syncSearchable(Attribute $attribute): void
    {
        SyncSearchable::dispatch($attribute->entity_type, $attribute->id)->afterCommit();
    }

    /** Dispatch the job to sync filterable index. */
    protected function syncFilterable(Attribute $attribute): void
    {
        SyncFilterable::dispatch($attribute->entity_type)->afterCommit();
    }
}
