<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\FilterableRegistry;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Client;

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

    /** Execute the job. */
    public function handle(
        FieldFactory $fieldFactory,
        EngineManager $engineManager,
        Client $client,
        FilterableRegistry $registry,
    ): void {
        if (! $this->isMeilisearchEngine($engineManager)) {
            return;
        }

        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! is_subclass_of($modelClass, Model::class) || ! method_exists($modelClass, 'searchableAs')) {
            return;
        }

        $indexName = (new $modelClass())->searchableAs();
        $paths = $this->getFilterablePaths($fieldFactory);
        $extra = $registry->resolve($modelClass);
        $configured = $this->getConfiguredFilterableAttributes($modelClass);

        $attributes = array_values(array_unique(array_merge($configured, $paths, $extra)));

        $client->index($indexName)->updateFilterableAttributes($attributes);
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
            ->with('type')
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