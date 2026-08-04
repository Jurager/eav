<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Support\AttributeQueryBuilder;

trait HasAttributeScopes
{
    /** Get attribute filter query builder. */
    protected function attributeFilterBuilder(): AttributeQueryBuilder
    {
        return AttributeManager::for($this->getEntityType())->builder();
    }

    /** Filter by single attribute value. */
    public function scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '='): Builder
    {
        if ($operator === 'tree') {
            return $this->scopeWhereAttributeTree($query, $code, $value);
        }

        return $this->applyAttributeSubquery($query, $code, $value, $operator);
    }

    /** Filter by attribute value using LIKE operator. */
    public function scopeWhereAttributeLike(Builder $query, string $code, string $value): Builder
    {
        return $this->scopeWhereAttribute($query, $code, $value, 'like');
    }

    /** Filter by attribute value range (inclusive). */
    public function scopeWhereAttributeBetween(Builder $query, string $code, float|int $min, float|int $max): Builder
    {
        return $this->applyAttributeSubquery($query, $code, [$min, $max], 'between');
    }

    /** Filter by attribute value IN a set. */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        return $this->applyAttributeSubquery($query, $code, $values, 'in');
    }

    /** Filter by multiple attribute conditions. */
    public function scopeWhereAttributes(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $condition) {
            $this->applyAttributeSubquery($query, $condition['code'], $condition['value'], $condition['operator'] ?? '=');
        }

        return $query;
    }

    /** Constrain query to keys matching attribute-filter subquery. */
    private function applyAttributeSubquery(Builder $query, string $code, mixed $value, string $operator): Builder
    {
        $sub = $this->attributeFilterBuilder()->subquery($code, $value, $operator);

        if (! $sub) {
            return $query;
        }

        return $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub);
    }

    /** Filter by attribute value and expand to NestedSet descendants. */
    public function scopeWhereAttributeTree(Builder $query, string $code, mixed $value): Builder
    {
        $sub = $this->attributeFilterBuilder()->subquery($code, $value, '=');

        if (! $sub) {
            return $query;
        }

        $model = $query->getModel();
        $matchingIds = $this->matchingAttributeIds($model, $sub);

        if (empty($matchingIds)) {
            return $query->whereKey([]);
        }

        $allIds = $this->expandToDescendants($model, $matchingIds);

        if (empty($allIds)) {
            return $query->whereKey([]);
        }

        return $query->whereIn($model->getQualifiedKeyName(), $allIds);
    }

    /** Resolve entity keys matched by attribute-filter subquery. */
    private function matchingAttributeIds(Model $model, Builder $sub): array
    {
        return $model->newQuery()
            ->whereIn($model->getQualifiedKeyName(), $sub)
            ->pluck($model->getKeyName())
            ->all();
    }


    /** Expand root IDs to all NestedSet descendants. */
    private function expandToDescendants(Model $model, array $ids): array
    {
        $treeQuery = $model->newQuery();

        if (! method_exists($treeQuery, 'whereDescendantOrSelf')) {
            return $ids;
        }

        $indexedIds = array_values($ids);

        return $treeQuery
            ->where(function (Builder $q) use ($indexedIds): void {
                foreach ($indexedIds as $i => $id) {
                    $q->whereDescendantOrSelf($id, $i === 0 ? 'and' : 'or');
                }
            })
            ->pluck($model->getKeyName())
            ->all();
    }
}
