<?php

declare(strict_types=1);

namespace Jurager\Eav\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Search\SearchResult;

/**
 * Read-only relation whose results come from a Search-backed query (Meilisearch)
 * rather than the database, resolved independently per parent model.
 *
 * Subclasses implement search() to run the actual query for a given parent and
 * declare the related model class + pagination; afterSearch() is a hook for
 * subclasses to surface the raw SearchResult (facets, total) elsewhere, since it
 * doesn't fit anywhere in Eloquent's relation-result shape.
 */
abstract class SearchRelation extends Relation
{
    public function __construct(Builder $query, Model $parent)
    {
        parent::__construct($query->whereKey([]), $parent);
    }

    /** Run the search scoped to this parent and return the raw result. */
    abstract protected function search(Model $parent): SearchResult;

    /** Eloquent model class to hydrate search hits into. */
    abstract protected function relatedModelClass(): string;

    /** Page size used for search()/hydration — must match what search() itself used. */
    abstract protected function perPage(): int;

    /** Page number used for search()/hydration — must match what search() itself used. */
    abstract protected function page(): int;

    /** Called after each search executes; override to surface facets/total elsewhere. */
    protected function afterSearch(SearchResult $result): void
    {
        //
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
        return $this->resolveFor($this->parent);
    }

    public function getEager(): Collection
    {
        return $this->related->newCollection();
    }

    /** Resolve the search + hydration for a single parent. */
    protected function resolveFor(Model $parent): Collection
    {
        $result = $this->search($parent);

        $this->afterSearch($result);

        return $result->paginate($this->relatedModelClass(), $this->perPage(), $this->page())->getCollection();
    }
}
