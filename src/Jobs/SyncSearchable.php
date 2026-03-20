<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Support\EavModels;

/**
 * Re-indexes all entities that have a stored value for the given attribute.
 *
 * Dispatched by AttributeObserver when an attribute's searchable flag changes
 * or when an attribute is soft-deleted. Implements ShouldBeUnique so duplicate
 * dispatches for the same (entity_type, attribute_id) pair are safely ignored.
 */
class SyncSearchable implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $entityType,
        protected int $attributeId,
    ) {}

    public function uniqueId(): string
    {
        return $this->entityType.':'.$this->attributeId;
    }

    public function handle(): void
    {
        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! method_exists($modelClass, 'searchable')) {
            return;
        }

        $subquery = EavModels::query('entity_attribute')
            ->select('entity_id')
            ->where('attribute_id', $this->attributeId)
            ->where('entity_type', $this->entityType);

        $keyName = (new $modelClass())->getKeyName();

        $modelClass::whereIn($keyName, $subquery)->searchable();
    }
}
