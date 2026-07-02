<?php

namespace Jurager\Eav\Relations;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Read-only relation exposing an entity's available attribute schema (including
 * scope and inheritance) as an eager-loadable JSON:API relationship.
 */
class AvailableAttributes extends Relation
{
    /** @param  Closure(Model): (Builder|null)  $resolver */
    public function __construct(Builder $query, Model $parent, protected Closure $resolver)
    {
        parent::__construct($query->whereKey([]), $parent);
    }

    public function addConstraints(): void
    {
        //
    }

    public function addEagerConstraints(array $models): void
    {
        //
    }

    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->resolveFor($model));
        }

        return $models;
    }

    public function getResults(): Collection
    {
        return self::scopedQuery($this->resolver, $this->parent)?->get() ?? $this->related->newCollection();
    }

    public function getEager(): Collection
    {
        return $this->related->newCollection();
    }

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
