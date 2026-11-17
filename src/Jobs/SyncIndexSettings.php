<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Contracts\Search\DerivesIndexSettings;
use Jurager\Eav\Search\Index\IndexFactory;
use Laravel\Scout\Contracts\UpdatesIndexSettings;

/**
 * Bring the engine's settings of an entity type's search index in line with its definition, through Scout.
 */
class SyncIndexSettings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $entityType,
    ) {}

    public function uniqueId(): string
    {
        return $this->entityType;
    }

    /**
     * Skipped for a model searched with Scout alone, whose index is not the package's to configure, and
     * for an engine whose settings follow from nothing — Scout's null driver among them.
     */
    public function handle(IndexFactory $indexes): void
    {
        $model = $indexes->model($this->entityType);

        if (! IndexFactory::defines($model)) {
            return;
        }

        $engine = $model->searchableUsing();

        if (! $engine instanceof DerivesIndexSettings || ! $engine instanceof UpdatesIndexSettings) {
            return;
        }

        $index = $indexes->for($model);
        $settings = $engine->indexSettings($index);

        if (config('scout.soft_delete', false) && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $settings = $engine->configureSoftDeleteFilter($settings);
        }

        $engine->updateIndexSettings($index->uid(), $settings);
    }
}
