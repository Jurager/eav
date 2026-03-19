<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Support\EavModels;

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

        $keyName = new $modelClass()->getKeyName();
        $modelClass::whereIn($keyName, $subquery)->searchable();

        $attribute = EavModels::query('attribute')->withTrashed()->find($this->attributeId);

        if ($attribute?->trashed()) {
            PruneAttribute::dispatch($this->attributeId);
        }
    }
}
