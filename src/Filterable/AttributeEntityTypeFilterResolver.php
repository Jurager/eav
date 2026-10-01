<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Models\Attribute;
use Jurager\Filterable\Applying\OperatorApplier;
use Jurager\Filterable\Contracts\FieldResolver;

class AttributeEntityTypeFilterResolver implements FieldResolver
{
    private const string FIELD = 'entity_type';

    private const array ALLOWED_OPERATORS = ['eq', 'ne', 'in', 'nin'];

    /** Resolve the filter against the attribute's applicable entity types. */
    public function resolve(Builder $query, string $name, mixed $value, Model $model): bool
    {
        if ($name !== self::FIELD || ! $model instanceof Attribute) {
            return false;
        }

        $query->whereHas('entityTypes', function (Builder $q) use ($value) {
            (new OperatorApplier())->apply($q, self::FIELD, self::ALLOWED_OPERATORS, $value);
        });

        return true;
    }
}
