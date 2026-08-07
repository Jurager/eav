<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\IndexCapability;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client;

class SyncIndexSettings implements ShouldBeUnique, ShouldQueue
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

    /** Execute the job. */
    public function handle(
        FieldFactory $fieldFactory,
        EngineManager $engineManager,
        Client $client,
    ): void {
        if (! $this->isMeilisearchEngine($engineManager)) {
            return;
        }

        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! is_subclass_of($modelClass, Model::class) || ! method_exists($modelClass, 'searchableAs')) {
            return;
        }

        $model = new $modelClass();
        $fields = $model instanceof InteractsWithIndex ? $model->indexFields() : [];

        $index = $client->index($model->searchableAs());

        $index->updateFilterableAttributes(array_values(array_unique(array_merge(
            $this->getConfiguredFilterableAttributes($modelClass),
            $this->getFilterablePaths($fieldFactory),
            $this->pathsFor($fields, IndexCapability::Filter),
        ))));

        $index->updateSortableAttributes($this->pathsFor($fields, IndexCapability::Sort));
    }

    /**
     * Index paths the model allows the given capability on.
     *
     * @param array<string, list<IndexCapability>> $fields
     * @return list<string>
     */
    protected function pathsFor(array $fields, IndexCapability $capability): array
    {
        return array_values(array_keys(array_filter(
            $fields,
            static fn (array $capabilities): bool => in_array($capability, $capabilities, true),
        )));
    }

    /** Determine if the current Scout engine is Meilisearch. */
    protected function isMeilisearchEngine(EngineManager $engineManager): bool
    {
        return class_exists(EngineManager::class) && class_exists(Client::class) && $engineManager->driver() instanceof MeilisearchEngine;
    }

    /**
     * Map filterable attributes to their indexable paths.
     *
     * @return list<string>
     */
    protected function getFilterablePaths(FieldFactory $fieldFactory): array
    {
        return Eav::$attributeModel::query()
            ->forEntity($this->entityType)
            ->where('filterable', true)
            ->get()
            ->flatMap(fn ($attribute) => $fieldFactory->make($attribute)->filterableKeys())
            ->map(fn (string $key) => "attributes.{$key}")
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get non-EAV filterable attributes declared in config.
     *
     * @return list<string>
     */
    protected function getConfiguredFilterableAttributes(string $modelClass): array
    {
        $settings = config('scout.meilisearch.index-settings', []);

        return $settings[$modelClass]['filterableAttributes'] ?? [];
    }
}
