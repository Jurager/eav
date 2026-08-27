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
        foreach ($models as $model) {
            $model->setRelation($relation, $this->resolveFor($model));
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

    /** Forward calls to the query resolved for parent. */
    public function __call($method, $parameters): mixed
    {
        $query = $this->queryForParent() ?? $this->related->newQuery()->whereKey([]);

        $result = $query->$method(...$parameters);

        if ($result instanceof Builder) {
            $this->resolvedQuery = $result;
        }

        return $result;
    }

    /** @return Builder<TRelatedModel>|null */
    private function queryForParent(): ?Builder
    {
        if (! $this->resolvedQuerySet) {
            $this->resolvedQuery = self::scopedQuery($this->resolver, $this->parent);
            $this->resolvedQuerySet = true;
        }

        return $this->resolvedQuery;
    }

    /** @return Collection<int, TRelatedModel> */
    protected function resolveFor(Model $parent): Collection
    {
        return self::scopedQuery($this->resolver, $parent)?->get()
            ?? $this->related->newCollection();
    }

    /**
     * @param  Closure(Model): (Builder<TRelatedModel>|null)  $resolver
     * @return Builder<TRelatedModel>|null
     */
    private static function scopedQuery(Closure $resolver, Model $parent): ?Builder
    {
        return $resolver($parent)?->setEagerLoads([]);
    }
}
