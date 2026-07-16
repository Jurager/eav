<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Eav;

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

        if (! $this->isValidModel($modelClass)) {
            return;
        }

        $model = new $modelClass();
        $table = (new (Eav::$entityAttributeModel)())->getTable();

        $modelClass::query()
            ->whereExists(
                fn ($query) => $query
                ->from($table)
                ->whereColumn('entity_id', "{$model->getTable()}.{$model->getKeyName()}")
                ->where('attribute_id', $this->attributeId)
                ->where('entity_type', $this->entityType)
            )
            ->chunkById(1000, fn ($models) => $models->each->searchable(), $model->getKeyName());
    }

    /** Determine if the model class is valid for indexing. */
    protected function isValidModel(?string $modelClass): bool
    {
        return $modelClass
            && is_subclass_of($modelClass, Model::class)
            && method_exists($modelClass, 'searchable');
    }
}
