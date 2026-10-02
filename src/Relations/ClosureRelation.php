<?php

declare(strict_types=1);

namespace Jurager\Eav\Relations;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Read-only relation whose results are resolved per-parent via a closure.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends Relation<TRelatedModel, TDeclaringModel>
 */
class ClosureRelation extends Relation
{
    /**
     * The query resolved for the current parent.
     *
     * @var Builder<TRelatedModel>|null
     */
    private ?Builder $resolvedQuery = null;

    private bool $resolvedQuerySet = false;

    /** Method calls applied via __call() while eager loading — replayed onto every other parent's own query. */
    private array $queryCallbacks = [];

    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  Closure(Model): (Builder<TRelatedModel>|null)  $resolver
     */
    public function __construct(Builder $query, Model $parent, protected Closure $resolver)
    {
        parent::__construct($query->whereKey([]), $parent);
    }

    /** Set the base constraints (not applicable for this custom relation). */
    public function addConstraints(): void
    {
        //
    }

    /** Set the constraints for eager loading (not applicable). */
    public function addEagerConstraints(array $models): void
    {
        //
    }

    /** Initialize the relation on a set of models. */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /** Match the results to their parents. */
    public function match(array $models, Collection $results, $relation): array
    {
        $resolved = [];

        foreach ($models as $model) {
            $query = $this->resolveQuery($model);

            if ($query === null) {
                $model->setRelation($relation, $this->related->newCollection());

                continue;
            }

            // Parents whose scope resolves to the same query share one round trip and one set of models.
            $key = $query->toRawSql();

            $model->setRelation($relation, $resolved[$key] ??= $query->get());
        }

        return $models;
    }

    /** @return Collection<int, TRelatedModel> */
    public function getResults(): Collection
    {
        return $this->queryForParent()?->get() ?? $this->related->newCollection();
    }

    /** @return Collection<int, TRelatedModel> */
    public function getEager(): Collection
    {
        return $this->related->newCollection();
    }

    /** Forward calls to the query resolved for parent, recording them to replay on every other parent. */
    public function __call($method, $parameters): mixed
    {
        $query = $this->queryForParent() ?? $this->related->newQuery()->whereKey([]);

        $result = $query->$method(...$parameters);

        if ($result instanceof Builder) {
            $this->resolvedQuery = $result;
            $this->queryCallbacks[] = [$method, $parameters];
        }

        return $result;
    }

    /** @return Builder<TRelatedModel>|null */
    private function queryForParent(): ?Builder
    {
        if (! $this->resolvedQuerySet) {
            $this->resolvedQuery = $this->scopedQuery($this->parent);
            $this->resolvedQuerySet = true;
        }

        return $this->resolvedQuery;
    }

    /** Resolve the query for a parent, replaying the calls made through the relation onto it. */
    private function resolveQuery(Model $parent): ?Builder
    {
        $query = $this->scopedQuery($parent);

        if ($query === null) {
            return null;
        }

        foreach ($this->queryCallbacks as [$method, $parameters]) {
            $query->$method(...$parameters);
        }

        return $query;
    }

    /**
     * @return Builder<TRelatedModel>|null
     */
    private function scopedQuery(Model $parent): ?Builder
    {
        $query = ($this->resolver)($parent);

        return $query?->setEagerLoads($this->query->getEagerLoads() + $query->getEagerLoads());
    }
}
