<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;

class Indexer
{
    /** @var class-string<Model>|null */
    private readonly ?string $model;

    private function __construct(string $entityType)
    {
        $model = Relation::getMorphedModel($entityType) ?? $entityType;

        $this->model = class_exists($model) ? $model : null;
    }

    /** @param string $entityType Morph key or class name of the entities. */
    public static function refresh(string $entityType): self
    {
        return new self($entityType);
    }

    /**
     * Queue reindexing of the given entities.
     *
     * @param array<int, int|string> $entityIds
     */
    public function ids(array $entityIds): void
    {
        if ($entityIds === [] || ! $this->indexable()) {
            return;
        }

        $this->model::query()->whereKey(array_unique($entityIds))->searchable();
    }

    /**
     * Queue reindexing of the given entities together with the variants reading their values.
     *
     * A variant's document is built from its parent's values as well, so a change on the parent moves its variants' documents too.
     *
     * @param array<int, int|string> $entityIds
     */
    public function withVariants(array $entityIds): void
    {
        $this->ids([...$entityIds, ...$this->variantIds($entityIds)]);
    }

    /**
     * Ids of the entities reading their values off the given ones.
     *
     * @param array<int, int|string> $entityIds
     * @return array<int, int|string>
     */
    private function variantIds(array $entityIds): array
    {
        if ($entityIds === [] || ! $this->indexable()) {
            return [];
        }

        $model = new $this->model();

        $relation = method_exists($model, 'attributeParentRelation') ? $model->attributeParentRelation() : null;

        if ($relation === null) {
            return [];
        }

        return $this->model::query()
            ->whereIn($relation->getForeignKeyName(), array_unique($entityIds))
            ->pluck($model->getKeyName())
            ->all();
    }

    /** Queue reindexing of everything touched since a point in time, in ranges of ids. */
    public function since(DateTimeInterface $since, int $chunk = 500): void
    {
        if (! $this->indexable()) {
            return;
        }

        $model = $this->model;
        $key = (new $model())->getScoutKeyName();

        $model::query()
            ->where('updated_at', '>=', $since)
            ->select($key)
            ->chunkById($chunk, static fn (Collection $ids) => dispatch(
                new MakeRangeSearchable($model, $ids->min($key), $ids->max($key)),
            ), $key);
    }

    /** Bulk imports suppress indexing until they finish and reindex in one pass of their own. */
    private function indexable(): bool
    {
        return $this->model !== null
            && in_array(Searchable::class, class_uses_recursive($this->model), true)
            && ! ModelObserver::syncingDisabledFor($this->model);
    }
}
