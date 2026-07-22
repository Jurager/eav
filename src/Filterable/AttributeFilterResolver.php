<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Filterable\Contracts\FieldResolver;
use Jurager\Filterable\Contracts\RelationResolver;
use Jurager\Filterable\Support\FilterOperator;

class AttributeFilterResolver implements FieldResolver, RelationResolver
{
    /** Resolve a field filter for EAV attributes. */
    public function resolve(Builder $query, string $name, mixed $value, Model $model): bool
    {
        if (! $model instanceof Attributable || ! preg_match('/^[\w\-]+$/', $name)) {
            return false;
        }

        $conditions = $this->parseConditions($value);

        if (empty($conditions)) {
            return false;
        }

        $this->applyConditions($query, $name, $conditions);

        return true;
    }

    /** Resolve a relation filter for EAV attributes. */
    public function resolveRelation(Builder $query, string $name, mixed $value, Model $model): bool
    {
        [$relation, $attribute] = array_pad(explode('.', $name, 2), 2, null);

        if (
            $attribute === null ||
            ! preg_match('/^\w+$/', $relation) ||
            ! preg_match('/^[\w\-]+$/', $attribute) ||
            ! method_exists($model, $relation)
        ) {
            return false;
        }

        $related = $model->{$relation}()->getRelated();

        if (! $related instanceof Attributable) {
            return false;
        }

        $conditions = $this->parseConditions($value);

        if (empty($conditions)) {
            return false;
        }

        $query->whereHas($relation, function (Builder $q) use ($attribute, $conditions): void {
            $this->applyConditions($q, $attribute, $conditions);
        });

        return true;
    }

    /**
     * Apply conditions to the query builder.
     *
     * @param array<int, array{0: string, 1: mixed}> $conditions
     */
    private function applyConditions(Builder $query, string $attribute, array $conditions): void
    {
        foreach ($conditions as [$operator, $operand]) {
            if ($operator === FilterOperator::Like->value && is_array($operand)) {
                $query->where(function (Builder $sub) use ($attribute, $operand): void {
                    foreach ($operand as $i => $val) {
                        $i === 0
                            ? $sub->whereAttribute($attribute, $val, FilterOperator::Like->value)
                            : $sub->orWhere(fn (Builder $q) => $q->whereAttribute($attribute, $val, FilterOperator::Like->value));
                    }
                });

                continue;
            }

            $query->whereAttribute($attribute, $operand, $operator);
        }
    }

    /**
     * Parse raw filter values into operator-operand pairs.
     *
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function parseConditions(mixed $value): array
    {
        if (! is_array($value)) {
            return [[FilterOperator::Eq->value, $value]];
        }

        if (array_is_list($value)) {
            return [[FilterOperator::In->value, $value]];
        }

        $conditions = [];

        foreach ($value as $alias => $operand) {
            $op = FilterOperator::fromAlias((string) $alias);

            if ($op === null) {
                continue;
            }

            if (in_array($op, [FilterOperator::In, FilterOperator::Nin], true) && ! is_array($operand)) {
                $operand = array_values(array_filter(explode(',', (string) $operand), static fn ($v): bool => $v !== ''));
            }

            $conditions[] = [$op->value, $operand];
        }

        return $conditions;
    }
}
