<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Registry\FieldTypeRegistry;
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

    public function handle(FieldTypeRegistry $fieldRegistry): void
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
            ->flatMap(function ($attribute) use ($fieldRegistry) {
                $field = $fieldRegistry->make($attribute);

                return collect($field->filterableKeys())->map(fn ($key) => "attributes.$key");
            })
            ->unique()
            ->values()
            ->all();

        $index = app(Client::class)->index($indexName);

        try {
            $existing = $index->getFilterableAttributes();
        } catch (\Throwable) {
            $existing = [];
        }

        $preserved = array_values(array_filter(
            $existing,
            fn ($attr) => ! str_starts_with($attr, 'attributes.'),
        ));

        $index->updateFilterableAttributes(
            array_values(array_unique(array_merge($preserved, $paths))),
        );
    }
}
