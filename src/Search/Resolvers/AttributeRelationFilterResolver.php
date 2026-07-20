<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Search\Contracts\FilterResolver;
use Jurager\Filterable\Support\FilterOperator;

class AttributeRelationFilterResolver implements FilterResolver
{
    /** Resolve a relation filter key into a concrete foreign key ID list. */
    public function resolve(string $name, mixed $value, Model $model): ?array
    {
        [$relation, $attribute] = array_pad(explode('.', $name, 2), 2, null);

        if ($attribute === null || ! preg_match('/^\w+$/', $relation) || ! method_exists($model, $relation)) {
            return null;
        }

        $rel = $model->{$relation}();
        $related = $rel->getRelated();

        if (! $related instanceof Attributable) {
            return null;
        }

        [$operator, $operand] = $this->parseCondition($value);

        $ids = $related->newQuery()
            ->whereAttribute($attribute, $operand, $operator)
            ->pluck($related->getKeyName())
            ->all();

        // If no results found, return a non-matching ID to ensure empty result set
        $idString = ! empty($ids) ? implode(',', $ids) : '0';

        return [
            "{$relation}.{$this->foreignKey($rel, $relation)}",
            [FilterOperator::In->value => $idString],
        ];
    }

    /** Determine foreign key name for the relation. */
    private function foreignKey(Relation $relation, string $name): string
    {
        return match (true) {
            $relation instanceof BelongsToMany => $relation->getRelatedPivotKeyName(),
            $relation instanceof BelongsTo     => $relation->getForeignKeyName(),
            default                            => Str::singular($name) . '_id',
        };
    }

    /**
     * Parse filter condition into operator-operand pair.
     *
     * @return array{0: string, 1: mixed}
     */
    private function parseCondition(mixed $value): array
    {
        if (! is_array($value)) {
            return [FilterOperator::Eq->value, $value];
        }

        $alias = array_key_first($value);

        return [
            FilterOperator::fromAlias((string) $alias)?->value ?? FilterOperator::Eq->value,
            reset($value),
        ];
    }
}
