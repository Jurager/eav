<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;

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
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->entityType}:{$this->attributeId}";
    }

    public function handle(): void
    {
        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            return;
        }

        if (! method_exists($modelClass, 'searchable')) {
            return;
        }

        $model = new $modelClass();
        $key = $model->getKeyName();
        $table = $model->getTable();

        $query = $modelClass::query()
            ->whereExists(function ($q) use ($table, $key) {
                $q->selectRaw(1)
                    ->from('entity_attribute')
                    ->whereColumn("entity_attribute.entity_id", "{$table}.{$key}")
                    ->where('entity_attribute.attribute_id', $this->attributeId)
                    ->where('entity_attribute.entity_type', $this->entityType);
            });

        $query->chunkById(1000, function ($models) {
            $models->each->searchable();
        }, $key);
    }
}