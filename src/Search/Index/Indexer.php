<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Laravel\Scout\ModelObserver;

/**
 * Queue the search documents of an entity type for rebuilding.
 */
class Indexer
{
    private readonly ?Model $model;

    public function __construct(string $entityType)
    {
        $this->model = app(IndexFactory::class)->model($entityType);
    }

    /** @param string $entityType Morph key or class name of the entities. */
    public static function for(string $entityType): self
    {
        return new self($entityType);
    }

    /**
     * Reindex the given entities.
     *
     * @param  array<int, int|string>  $entityIds
     */
    public function ids(array $entityIds): void
    {
        if ($entityIds === [] || ! $this->indexable()) {
            return;
        }

        $this->model->newQuery()->whereKey(array_unique($entityIds))->searchable();
    }

    /**
     * Reindex the given entities and the variants that inherit their values.
     *
     * @param  array<int, int|string>  $entityIds
     */
    public function withVariants(array $entityIds): void
    {
        $this->ids([...$entityIds, ...$this->variantIds($entityIds)]);
    }

    /**
     * Reindex every entity holding a value of the attribute, and the variants inheriting it.
     */
    public function attribute(int|string $attributeId, int $chunk = 500): void
    {
        if (! $this->indexable() || ! $this->model instanceof Attributable) {
            return;
        }

        $values = new (Eav::$entityAttributeModel);

        $values->newQuery()
            ->where('attribute_id', $attributeId)
            ->where('entity_type', $this->model->getEntityType())
            ->select('entity_id')
            ->distinct()
            ->orderBy('entity_id')
            ->chunk($chunk, fn ($rows) => $this->withVariants($rows->pluck('entity_id')->all()));
    }

    /**
     * Reindex everything touched since a point in time, in ranges of ids.
     */
    public function since(DateTimeInterface $since, int $chunk = 500): void
    {
        if (! $this->indexable()) {
            return;
        }

        $class = $this->model::class;
        $key = $this->model->getScoutKeyName();

        $this->model->newQuery()
            ->where($this->model->getUpdatedAtColumn(), '>=', $since)
            ->select($key)
            ->chunkById($chunk, static fn (Collection $ids) => dispatch(
                new MakeRangeSearchable($class, $ids->min($key), $ids->max($key)),
            ), $key);
    }

    /**
     * Reindex every entity of the type.
     */
    public function all(?int $chunk = null): void
    {
        if ($this->model !== null) {
            $this->model::makeAllSearchable($chunk);
        }
    }

    /**
     * Remove every document of the type from the index.
     */
    public function flush(): void
    {
        if ($this->model !== null) {
            $this->model::removeAllFromSearch();
        }
    }

    /**
     * Ids of the entities reading their values off the given ones.
     *
     * @param  array<int, int|string>  $entityIds
     * @return array<int, int|string>
     */
    private function variantIds(array $entityIds): array
    {
        if ($entityIds === [] || ! $this->indexable()) {
            return [];
        }

        $relation = $this->model instanceof Attributable ? $this->model->attributeParentRelation() : null;

        if ($relation === null) {
            return [];
        }

        return $this->model->newQuery()
            ->whereIn($relation->getForeignKeyName(), array_unique($entityIds))
            ->pluck($this->model->getKeyName())
            ->all();
    }

    /**
     * Bulk imports suppress indexing until they finish and reindex in one pass of their own.
     */
    private function indexable(): bool
    {
        return $this->model !== null && ! ModelObserver::syncingDisabledFor($this->model::class);
    }
}
