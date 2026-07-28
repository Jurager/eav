<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Eav;
use Jurager\Filterable\Concerns\HasFilterable;
use Jurager\Filterable\Contracts\RelationResolver;

/**
 * Resolve constraint on an eager-loaded enum relation, keeping only the options actually selected by the matching entities
 */
class AttributeEnumUsageResolver implements RelationResolver
{
    /** Segment marking a filter as a usage constraint: `<relation>.used_by.<entity_type>`. */
    private const string MARKER = 'used_by';

    /** Operator a field must permit to be treated as the entity's hierarchical scope. */
    private const string SCOPE_OPERATOR = 'tree';

    /** Constrain an eager-loaded enum relation to the options used by the matching entities. */
    public function resolveRelation(Builder $query, string $name, mixed $value, Model $model): bool
    {
        $segments = explode('.', $name);

        if (count($segments) !== 3 || $segments[1] !== self::MARKER) {
            return false;
        }

        [$relation, , $entityType] = $segments;

        if (! is_array($value) || $value === [] || ! $model->isRelation($relation)) {
            return false;
        }

        $enumModel = Eav::$attributeEnumModel;

        if (! $model->{$relation}()->getRelated() instanceof $enumModel) {
            return false;
        }

        $entities = $this->entityQuery($entityType, $value);

        if ($entities === null) {
            return false;
        }

        // Compose with any constraint already registered for the relation instead of replacing it.
        $existing = $query->getEagerLoads()[$relation] ?? null;

        $query->with([$relation => static function ($enums) use ($existing, $entities): void {
            if ($existing !== null) {
                $existing($enums);
            }

            $enums->usedBy($entities);
        }]);

        return true;
    }

    /**
     * Build the filtered query for the entities the enum options must be used by.
     *
     * @param  array<string, mixed>  $conditions  Operators and operands, e.g. `['tree' => '1,2']`.
     */
    private function entityQuery(string $entityType, array $conditions): ?Builder
    {
        $entityClass = Relation::getMorphedModel($entityType);

        if ($entityClass === null || ! is_subclass_of($entityClass, Model::class)) {
            return null;
        }

        if (! in_array(HasFilterable::class, class_uses_recursive($entityClass), true)) {
            return null;
        }

        $entity = new $entityClass();
        $field  = $this->scopeField($entity);

        return $field === null ? null : $entity->newQuery()->filter([$field => $conditions]);
    }

    /** Find the field the entity declares as its hierarchical scope. */
    private function scopeField(Model $entity): ?string
    {
        foreach ($entity->filterableFields() as $field => $operators) {
            if (in_array(self::SCOPE_OPERATOR, (array) $operators, true)) {
                return $field;
            }
        }

        return null;
    }
}
