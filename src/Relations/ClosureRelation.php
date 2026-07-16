<?php

namespace Jurager\Eav\Relations;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Read-only relation whose results are resolved per-parent via a closure. */
class ClosureRelation extends Relation
{
    /** @param  Closure(Model): (Builder|null)  $resolver */
    public function __construct(Builder $query, Model $parent, protected Closure $resolver)
    {
        parent::__construct($query->whereKey([]), $parent);
    }

    /** Set the base constraints on the relation query. */
    public function addConstraints(): void
    {
        //
    }

    /** Set the constraints for an eager load of the relation. */
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

    /** Match the eagerly loaded results to their parents. */
    public function match(array $models, Collection $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->resolveFor($model));
        }

        return $models;
    }

    /** Get the results of the relationship. */
    public function getResults(): Collection
    {
        return self::scopedQuery($this->resolver, $this->parent)?->get() ?? $this->related->newCollection();
    }

    /** Get the relationship for eager loading. */
    public function getEager(): Collection
    {
        return $this->related->newCollection();
    }

    /** Forward query builder calls to the resolved per-parent query. */
    public function __call($method, $parameters): mixed
    {
        $query = ($this->resolver)($this->parent) ?? $this->related->newQuery()->whereKey([]);

        return $query->$method(...$parameters);
    }

    /** @return Collection<int, Model> */
    protected function resolveFor(Model $parent): Collection
    {
        $query = self::scopedQuery($this->resolver, $parent);

        return $query instanceof Builder ? $query->get() : $this->related->newCollection();
    }

    /**
     * Resolve the per-parent query without eager loads.
     *
     * @param  Closure(Model): (Builder|null)  $resolver
     */
    private static function scopedQuery(Closure $resolver, Model $parent): ?Builder
    {
        return $resolver($parent)?->setEagerLoads([]);
    }
}
