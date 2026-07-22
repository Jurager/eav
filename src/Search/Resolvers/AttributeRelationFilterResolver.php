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

/** Resolve a relation filter key into a concrete foreign key ID list. */
class AttributeRelationFilterResolver implements FilterResolver
{
    /** Resolve filter key. */
    public function resolve(string $name, mixed $value, Model $model): ?array
    {
        if (! str_contains($name, '.')) {
            return null;
        }

        [$relation, $attribute] = explode('.', $name, 2);

        if (! preg_match('/^\w+$/', $relation) || ! method_exists($model, $relation)) {
            return null;
        }

        $instance = $model->{$relation}();
        $related = $instance->getRelated();

        if (! $related instanceof Attributable) {
            return null;
        }

        [$operator, $operand] = $this->parseCondition($value);

        $ids = $related->newQuery()
            ->whereAttribute($attribute, $operand, $operator)
            ->pluck($related->getKeyName())
            ->all();

        // If no results found, return '0' to ensure an empty result set
        $idString = empty($ids) ? '0' : implode(',', $ids);
        $resolvedKey = "{$relation}.{$this->foreignKey($instance, $relation)}";

        return [
            $resolvedKey,
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
        if (! is_array($value) || empty($value)) {
            return [FilterOperator::Eq->value, $value];
        }

        foreach ($value as $alias => $operand) {
            $operator = FilterOperator::fromAlias((string) $alias)?->value ?? FilterOperator::Eq->value;

            return [$operator, $operand];
        }

        return [FilterOperator::Eq->value, null];
    }
}