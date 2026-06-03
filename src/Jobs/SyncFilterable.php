<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Support\EavModels;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client;

/**
 * Sync Meilisearch filterableAttributes for the given entity type.
 *
 * Dispatched by AttributeObserver when an attribute's filterable flag changes
 * or when an attribute is deleted or restored.
 *
 * No-op when Scout is not installed or the driver is not Meilisearch.
 */
class SyncFilterable implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $entityType,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->entityType;
    }

    public function handle(FieldFactory $fieldFactory): void
    {
        if (! class_exists(EngineManager::class) || ! class_exists(Client::class)) {
            return;
        }

        $engine = app(EngineManager::class)->driver();

        if (! $engine instanceof MeilisearchEngine) {
            return;
        }

        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! is_subclass_of($modelClass, Model::class)) {
            return;
        }

        if (! method_exists($modelClass, 'searchableAs')) {
            return;
        }

        $indexName = (new $modelClass())->searchableAs();

        $paths = EavModels::query('attribute')
            ->forEntity($this->entityType)
            ->where('filterable', true)
            ->with('type')
            ->get()
            ->flatMap(function ($attribute) use ($fieldFactory) {
                $field = $fieldFactory->make($attribute);

                return collect($field->filterableKeys())->map(fn ($key) => "attributes.$key");
            })
            ->unique()
            ->values()
            ->all();

        $index = app(Client::class)->index($indexName);

        $index->updateFilterableAttributes(
            array_values(array_unique(array_merge($this->configuredFilterableAttributes($modelClass), $paths))),
        );
    }

    /**
     * Non-EAV filterable attributes declared for the model in scout config.
     *
     * Read from configuration rather than the live index so the result is
     * deterministic and independent of the order in which this job and
     * `scout:sync-index-settings` apply their (asynchronous) Meilisearch tasks.
     *
     * @param  class-string  $modelClass
     * @return array<int, string>
     */
    private function configuredFilterableAttributes(string $modelClass): array
    {
        $indexSettings = config('scout.meilisearch.index-settings', []);

        return $indexSettings[$modelClass]['filterableAttributes'] ?? [];
    }
}
