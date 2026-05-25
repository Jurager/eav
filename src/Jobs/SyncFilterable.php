<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Support\EavModels;

/**
 * Synchronises filterableAttributes on the Meilisearch index for the given entity type.
 *
 * Dispatched by AttributeObserver whenever an attribute's filterable flag changes,
 * or when an attribute is deleted/restored. Reads the current index settings from
 * Meilisearch, strips out all "attributes.*" paths that are EAV-managed, and replaces
 * them with the current set of filterable attribute paths. Non-EAV paths (e.g. "id",
 * "is_active") are preserved unchanged.
 *
 * Is a no-op when Scout is not installed or when the active driver is not Meilisearch.
 * Implements ShouldBeUnique so redundant dispatches for the same entity type collapse.
 */
class SyncFilterable implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $entityType,
    ) {}

    public function uniqueId(): string
    {
        return $this->entityType;
    }

    public function handle(FieldTypeRegistry $fieldRegistry): void
    {
        if (! class_exists(\Laravel\Scout\EngineManager::class)) {
            return;
        }

        $engine = app(\Laravel\Scout\EngineManager::class)->driver();

        if (! $engine instanceof \Laravel\Scout\Engines\MeilisearchEngine) {
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

        $index = $engine->getMeilisearch()->index($indexName);

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
