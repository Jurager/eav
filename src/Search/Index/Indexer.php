<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Laravel\Scout\ModelObserver;

/**
 * Queue search documents for rebuilding. Entity types are given by morph key or class name.
 */
class Indexer
{
    public function __construct(private readonly IndexFactory $indexes) {}

    /**
     * Reindex the given entities.
     *
     * @param  array<int, int|string>  $entityIds
     */
    public function ids(string $entityType, array $entityIds): void
    {
        if ($entityIds !== [] && ($model = $this->model($entityType)) !== null) {
            $model->newQuery()->whereKey(array_unique($entityIds))->searchable();
        }
    }

    /**
     * Reindex the given entities and the variants that inherit their values.
     *
     * @param  array<int, int|string>  $entityIds
     */
    public function withVariants(string $entityType, array $entityIds): void
    {
        $this->ids($entityType, [...$entityIds, ...$this->variantIds($entityType, $entityIds)]);
    }

    /**
     * Reindex every entity holding a value of the attribute, and the variants inheriting it.
     */
    public function attribute(string $entityType, int|string $attributeId, int $chunk = 500): void
    {
        $model = $this->model($entityType);

        if (! $model instanceof Attributable) {
            return;
        }

        (new (Eav::$entityAttributeModel))->newQuery()
            ->where('attribute_id', $attributeId)
            ->where('entity_type', $model->getEntityType())
            ->select('entity_id')
            ->distinct()
            ->orderBy('entity_id')
            ->chunk($chunk, fn ($rows) => $this->withVariants($entityType, $rows->pluck('entity_id')->all()));
    }

    /**
     * Reindex everything touched since a point in time.
     */
    public function since(string $entityType, DateTimeInterface $since, int $chunk = 500): void
    {
        if (($model = $this->model($entityType)) !== null) {
            $model->newQuery()->where($model->getUpdatedAtColumn(), '>=', $since)->searchable($chunk);
        }
    }

    /**
     * Remove the documents of the given keys that no entity stands behind any more.
     *
     * @param  array<int, int|string>  $keys  Scout keys found in the index.
     */
    public function prune(string $entityType, array $keys): void
    {
        if ($keys === [] || ($model = $this->model($entityType)) === null) {
            return;
        }

        $name = $model->getScoutKeyName();

        // Scopes hide entities without removing them: only a key no row has at all is gone.
        $existing = $model->newQueryWithoutScopes()->whereIn($model->qualifyColumn($name), $keys)->pluck($name)->map(strval(...))->all();
        $gone = array_values(array_diff(array_map(strval(...), $keys), $existing));

        if ($gone !== []) {
            $model->newCollection(array_map(
                static fn (string $key): Model => $model->newInstance()->forceFill([$name => $key]),
                $gone,
            ))->unsearchable();
        }
    }

    /**
     * Reindex every entity of the type.
     */
    public function all(string $entityType, ?int $chunk = null): void
    {
        $this->indexes->model($entityType)?->makeAllSearchable($chunk);
    }

    /**
     * Ids of the entities reading their values off the given ones.
     *
     * @param  array<int, int|string>  $entityIds
     * @return array<int, int|string>
     */
    private function variantIds(string $entityType, array $entityIds): array
    {
        $model = $this->model($entityType);
        $relation = $model instanceof Attributable ? $model->attributeParentRelation() : null;

        if ($entityIds === [] || $relation === null) {
            return [];
        }

        return $model->newQuery()
            ->whereIn($relation->getForeignKeyName(), array_unique($entityIds))
            ->pluck($model->getKeyName())
            ->all();
    }

    /**
     * The model to index, unless indexing is suspended: bulk imports reindex in one pass of their own.
     */
    private function model(string $entityType): ?Model
    {
        $model = $this->indexes->model($entityType);

        return $model !== null && ! ModelObserver::syncingDisabledFor($model::class) ? $model : null;
    }
}
