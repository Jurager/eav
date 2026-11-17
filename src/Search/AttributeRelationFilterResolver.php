<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Contracts\Search\FilterResolver;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Filterable\Support\FilterOperator;

/**
 * Resolve a filter on a related entity's attribute (`category.color`) into a list of related ids.
 *
 * Opt in through `eav.search.resolvers`. The index must declare the relation's key filterable:
 * the foreign key itself for a belongs-to (`category_id`), `{relation}.{key}` otherwise (`categories.category_id`).
 */
class AttributeRelationFilterResolver implements FilterResolver
{
    /**
     * Resolve filter key.
     */
    public function resolve(string $name, mixed $value, Model $model): ?array
    {
        if (! str_contains($name, '.')) {
            return null;
        }

        [$relation, $attribute] = explode('.', $name, 2);

        if (! preg_match('/^\w+$/', $relation) || ! $model->isRelation($relation)) {
            return null;
        }

        $instance = $model->{$relation}();
        $related = $instance->getRelated();

        if (! $related instanceof Attributable) {
            return null;
        }

        [$operator, $operand] = $this->parseCondition($value);

        $limit = (int) config('eav.search.relation_limit', 1000);

        $ids = $related->newQuery()
            ->whereAttribute($attribute, $operand, $operator)
            ->limit($limit + 1)
            ->pluck($related->getKeyName())
            ->all();

        // The ids travel in the filter expression: refuse a list too long to send rather than cut it short.
        if (count($ids) > $limit) {
            throw InvalidSearchQueryException::tooManyMatches($name, $limit);
        }

        $foreignKey = $this->foreignKey($instance, $relation);

        // A belongs-to key is a column of the entity itself; other relations are indexed under the relation's name.
        $resolvedKey = $instance instanceof BelongsTo ? $foreignKey : "{$relation}.{$foreignKey}";

        return [
            $resolvedKey,
            [FilterOperator::In->value => $ids],
        ];
    }

    /**
     * Determine foreign key name for the relation.
     */
    private function foreignKey(Relation $relation, string $name): string
    {
        return match (true) {
            $relation instanceof BelongsToMany => $relation->getRelatedPivotKeyName(),
            $relation instanceof BelongsTo => $relation->getForeignKeyName(),
            default => Str::singular($name).'_id',
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
