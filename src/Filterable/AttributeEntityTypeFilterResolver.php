<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Models\Attribute;
use Jurager\Filterable\Contracts\FieldResolver;
use Jurager\Filterable\Exceptions\OperatorNotAllowedException;

class AttributeEntityTypeFilterResolver implements FieldResolver
{
    private const string FIELD = 'entity_type';

    /** Whether each allowed operator asks for the absence of a match. */
    private const array OPERATORS = ['eq' => false, 'ne' => true, 'in' => false, 'nin' => true];

    /** Resolve the filter against the attribute's applicable entity types. */
    public function resolve(Builder $query, string $name, mixed $value, Model $model): bool
    {
        if ($name !== self::FIELD || ! $model instanceof Attribute) {
            return false;
        }

        [$operator, $operand] = $this->condition($value);

        if (! array_key_exists($operator, self::OPERATORS)) {
            throw new OperatorNotAllowedException($operator);
        }

        $types = array_filter(
            is_string($operand) ? explode(',', $operand) : (array) $operand,
            static fn (mixed $type): bool => is_string($type) && $type !== '',
        );

        $match = static fn (Builder $q): Builder => $q->whereIn(self::FIELD, $types);

        self::OPERATORS[$operator]
            ? $query->whereDoesntHave('entityTypes', $match)
            : $query->whereHas('entityTypes', $match);

        return true;
    }

    private function condition(mixed $value): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            return [(string) array_key_first($value), reset($value)];
        }

        return [is_array($value) ? 'in' : 'eq', $value];
    }
}
