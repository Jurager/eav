<?php

namespace Jurager\Eav\Filtering;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Filterable\Contracts\FieldResolverInterface;
use Jurager\Filterable\Contracts\RelationResolverInterface;
use Jurager\Filterable\Support\FilterOperators;

/**
 * Handles filter keys not declared in $filterable by treating them as EAV attribute
 * codes on models that implement scopeWhereAttribute (HasAttributes trait).
 *
 * Implements both FieldResolverInterface (plain fields) and RelationResolverInterface
 * (dotted relation.attributeCode fields). Registered as a single entry under the
 * 'filterable.resolvers' container tag by EavServiceProvider.
 */
class EavFieldResolver implements FieldResolverInterface, RelationResolverInterface
{
    public function resolve(Builder $query, string $name, mixed $value, Model $model): bool
    {
        if (!method_exists($model, 'scopeWhereAttribute') || !preg_match('/^[\w\-]+$/', $name)) {
            return false;
        }

        $conditions = $this->parseOperatorConditions($value);

        if (empty($conditions)) {
            return false;
        }

        $this->applyConditions($query, $name, $conditions);

        return true;
    }

    public function resolveRelation(Builder $query, string $name, mixed $value, Model $model): bool
    {
        [$relationName, $attribute] = array_pad(explode('.', $name, 2), 2, null);

        if (
            !$attribute ||
            !preg_match('/^\w+$/', $relationName) ||
            !preg_match('/^[\w\-]+$/', $attribute) ||
            !method_exists($model, $relationName)
        ) {
            return false;
        }

        $related = $model->{$relationName}()->getRelated();

        if (!method_exists($related, 'scopeWhereAttribute')) {
            return false;
        }

        $conditions = $this->parseOperatorConditions($value);

        if (empty($conditions)) {
            return false;
        }

        $query->whereHas($relationName, function (Builder $q) use ($attribute, $conditions): void {
            $this->applyConditions($q, $attribute, $conditions);
        });

        return true;
    }

    /**
     * Apply a list of parsed operator conditions using EAV query methods.
     *
     * Multiple `like` operands within one condition are OR'd inside a grouped WHERE.
     *
     * @param  array<int, array{operator: string, operand: mixed}>  $conditions
     */
    private function applyConditions(Builder $query, string $attribute, array $conditions): void
    {
        foreach ($conditions as ['operator' => $operator, 'operand' => $operand]) {
            if ($operator === 'tree') {
                $query->whereAttributeTree($attribute, $operand);

                continue;
            }

            if ($operator === 'like' && is_array($operand)) {
                $query->where(function (Builder $sub) use ($attribute, $operand): void {
                    foreach ($operand as $i => $val) {
                        $i === 0
                            ? $sub->whereAttribute($attribute, $val, 'like')
                            : $sub->orWhere(fn ($q) => $q->whereAttribute($attribute, $val, 'like'));
                    }
                });

                continue;
            }

            $query->whereAttribute($attribute, $operand, $operator);
        }
    }

    /**
     * Parse a raw filter value into {operator, operand} pairs.
     *
     * Scalar   → [{operator: '=', operand: value}]
     * List     → [{operator: 'in', operand: [...]}]
     * Assoc    → one entry per operator key (validated against FilterOperators::MAP).
     *
     * @return array<int, array{operator: string, operand: mixed}>
     */
    private function parseOperatorConditions(mixed $value): array
    {
        if (!is_array($value)) {
            return [['operator' => '=', 'operand' => $value]];
        }

        if (array_is_list($value)) {
            return [['operator' => 'in', 'operand' => $value]];
        }

        $conditions = [];

        foreach ($value as $op => $operand) {
            $operator = FilterOperators::MAP[$op] ?? null;

            abort_if($operator === null, 400, "Unknown filter operator: '$op'.");

            if (!is_array($operand) && in_array($operator, ['in', 'nin'], true)) {
                $operand = array_map('trim', explode(',', (string) $operand));
            }

            if (in_array($operator, ['between', 'not_between'], true)) {
                if (!is_array($operand)) {
                    $operand = array_map('trim', explode(',', (string) $operand));
                }
                abort_if(count($operand) !== 2, 400, "Operator '$op' requires exactly 2 values.");
            }

            $conditions[] = ['operator' => $operator, 'operand' => $operand];
        }

        return $conditions;
    }
}
