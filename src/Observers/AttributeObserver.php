<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Eav;
use Jurager\Eav\Events\AttributeCreated;
use Jurager\Eav\Events\AttributeDeleted as AttributeDeletedEvent;
use Jurager\Eav\Events\AttributeUpdated;
use Jurager\Eav\Jobs\PruneAttribute;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\SchemaRegistry;

class AttributeObserver
{
    public function __construct(
        protected SchemaRegistry $schema,
        protected EnumRegistry $enums,
        protected AttributeRegistry $registry,
    ) {}

    /** Handle the "created" event. */
    public function created(Attribute $attribute): void
    {
        $this->invalidateCaches($attribute);
        $this->syncAttributeStates($attribute);

        AttributeCreated::dispatch($attribute);
    }

    /** Handle the "updated" event. */
    public function updated(Attribute $attribute): void
    {
        $this->invalidateCaches($attribute);

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

        $this->invalidateCaches($attribute);
        $this->syncAttributeStates($attribute);

        Eav::$entityAttributeModel::query()
            ->where('attribute_id', $attribute->id)
            ->delete();

        AttributeDeletedEvent::dispatch($attribute);
    }

    /** Handle the "forceDeleted" event. */
    public function forceDeleted(Attribute $attribute): void
    {
        $this->invalidateCaches($attribute);
        $this->enums->forget($attribute->id);

        $this->syncAttributeStates($attribute);
        PruneAttribute::dispatch($attribute->id);

        AttributeDeletedEvent::dispatch($attribute);
    }

    /** Handle the "restored" event. */
    public function restored(Attribute $attribute): void
    {
        $this->invalidateCaches($attribute);
        $this->syncAttributeStates($attribute);
    }

    /** Clear the schema and attribute registry caches for every entity type the attribute is applicable to. */
    protected function invalidateCaches(Attribute $attribute): void
    {
        $this->forgetCaches($attribute->applicableEntityTypes());
    }

    /** Clears schema and attribute registry caches for the given entity types. */
    public function forgetCaches(array $entityTypes): void
    {
        foreach ($entityTypes as $entityType) {
            $this->schema->forget($entityType);
            $this->registry->forget($entityType);
        }
    }

    /** Syncs attribute states for all applicable entity types. */
    public function syncAttributeStates(Attribute $attribute): void
    {
        if ($attribute->searchable) {
            $this->syncSearchable($attribute);
        }

        if ($attribute->filterable) {
            $this->syncFilterable($attribute);
        }
    }

    /** Dispatch the job to sync searchable index for every applicable entity type. */
    protected function syncSearchable(Attribute $attribute): void
    {
        foreach ($attribute->applicableEntityTypes() as $entityType) {
            SyncSearchable::dispatch($entityType, $attribute->id)->afterCommit();
        }
    }

    /** Dispatch the job to sync filterable index for every applicable entity type. */
    protected function syncFilterable(Attribute $attribute): void
    {
        foreach ($attribute->applicableEntityTypes() as $entityType) {
            SyncIndexSettings::dispatch($entityType)->afterCommit();
        }
    }
}
