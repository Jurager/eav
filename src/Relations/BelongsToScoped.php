<?php

declare(strict_types=1);

namespace Jurager\Eav\Relations;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A BelongsTo relationship constrained by an additional scope. */
class BelongsToScoped extends BelongsTo
{
    /** Create a new scoped belongs to relationship instance. */
    public function __construct(
        Builder $query,
        Model $child,
        string $foreignKey,
        string $ownerKey,
        string $relationName,
        protected readonly string $foreignScopeKey,
        protected readonly string $ownerScopeKey,
    ) {
        parent::__construct($query, $child, $foreignKey, $ownerKey, $relationName);
    }

    /** Set the base constraints on the relation query. */
    public function addConstraints(): void
    {
        parent::addConstraints();

        if (static::$constraints) {
            $this->query->where(
                $this->ownerScopeKey,
                $this->child->getAttribute($this->foreignScopeKey)
            );
        }
    }

    /** Set the constraints for an eager load of the relation. */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->query->whereIn(
            $this->ownerScopeKey,
            $this->getEagerScopeKeys($models)
        );
    }

    /** Match the eagerly loaded results to their parents. */
    public function match(array $models, Collection $results, $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->getAttribute($this->foreignKey));
            $scope = $this->getDictionaryKey($model->getAttribute($this->foreignScopeKey));

            if ($key !== null && isset($dictionary[$scope][$key])) {
                $model->setRelation($relation, $dictionary[$scope][$key]);
            }
        }

        return $models;
    }

    /** Build model dictionary keyed by scope and owner key. */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $this->getDictionaryKey($result->getAttribute($this->ownerKey));
            $scope = $this->getDictionaryKey($result->getAttribute($this->ownerScopeKey));

            $dictionary[$scope][$key] = $result;
        }

        return $dictionary;
    }

    /** Get the unique scoped keys for an eager load. */
    protected function getEagerScopeKeys(array $models): array
    {
        return collect($models)
            ->map(fn (Model $model) => $model->getAttribute($this->foreignScopeKey))
            ->filter(fn ($scope) => ! is_null($scope))
            ->unique()
            ->values()
            ->all();
    }
}
