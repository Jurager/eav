<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Attributes\Persistence\ValuePruner;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Schema\SchemaSynchronizer;

/**
 * Synchronize cached schemas and search indexes when attributes change.
 */
class AttributeObserver
{
    public function __construct(
        protected SchemaSynchronizer $sync,
        protected EnumRegistry $enums,
        protected ValuePruner $pruner,
    ) {
    }

    public function created(Attribute $attribute): void
    {
        $this->sync->refresh($attribute);
    }

    public function updated(Attribute $attribute): void
    {
        $this->sync->refresh(
            $attribute,
            documents: $attribute->wasChanged(['searchable', 'filterable']),
            settings: $attribute->wasChanged('filterable'),
        );
    }

    /**
     * A soft-deleted attribute keeps its values, so restoring it brings them back.
     */
    public function deleted(Attribute $attribute): void
    {
        if (! $attribute->isForceDeleting()) {
            $this->sync->refresh($attribute);
        }
    }

    public function restored(Attribute $attribute): void
    {
        $this->sync->refresh($attribute);
    }

    /**
     * Remove the stored values before the row goes, while they can still be found by attribute.
     */
    public function forceDeleting(Attribute $attribute): void
    {
        // Remember the entity types for forceDeleted(): their rows go with the attribute.
        $attribute->setRelation('entityTypes', $attribute->entityTypes()->get());

        $this->pruner->prune($attribute->getKey());
    }

    public function forceDeleted(Attribute $attribute): void
    {
        $this->enums->forget($attribute->getKey());
        $this->sync->refresh($attribute);
    }
}
