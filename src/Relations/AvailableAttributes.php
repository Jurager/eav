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
    /**
     * @param  Builder  $query  Base query of the related (attribute) model.
     * @param  Closure(Model): (Builder|null)  $resolver  Per-parent available-attributes query.
     */
    public function __construct(Builder $query, Model $parent, protected Closure $resolver)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        // No shared constraints: the attribute set is resolved per parent.
    }

    public function addEagerConstraints(array $models): void
    {
        // Resolved per parent in match(); nothing to constrain on the shared query.
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
        return $this->parent->getKey() !== null
            ? $this->resolveFor($this->parent)
            : $this->related->newCollection();
    }

    public function getEager(): Collection
    {
        return $this->related->newCollection();
    }

    /**
     * Resolve the available-attributes query for a single parent and fetch the models.
     *
     * @return Collection<int, Model>
     */
    protected function resolveFor(Model $parent): Collection
    {
        $query = ($this->resolver)($parent);

        return $query instanceof Builder ? $query->get() : $this->related->newCollection();
    }
}
