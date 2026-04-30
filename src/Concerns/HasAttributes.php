<?php

namespace Jurager\Eav\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Support\AttributeInheritanceResolver;
use Jurager\Eav\Support\AttributeValidator;
use Jurager\Eav\Support\EavModels;

/**
 * Adds EAV attribute support to Eloquent models.
 *
 * Requires the model to implement Attributable.
 *
 * @property Collection $attribute_relation
 *
 * @phpstan-require-implements Attributable
 */
trait HasAttributes
{
    /**
     * Cached AttributeManager instance — one per model instance.
     */
    protected ?AttributeManager $attributeManager = null;

    /**
     * Override in models to declare scoped uniqueness for specific EAV attributes.
     *
     * Return a map of [attributeCode => callable($query, $entity)].
     * The callable receives the entity_attribute Builder and the model instance,
     * and should add WHERE conditions to restrict the uniqueness check scope.
     *
     * Example:
     *   return [
     *       'code' => function ($query, self $entity) {
     *           $query->whereIn('entity_id', static::query()->whereDescendantOrSelf($entity->id)->select('id'));
     *       },
     *   ];
     *
     * @return array<string, callable>
     */
    protected static function attributeUniqueScopes(): array
    {
        return [];
    }

    /**
     * Return the AttributeManager for this entity (lazy-loaded, cached).
     */
    public function eav(): AttributeManager
    {
        return $this->attributeManager ??= AttributeManager::for($this);
    }

    /**
     * Validate and fill attribute input, returning filled Field instances keyed by code.
     *
     * @param  array<int, array{code: string, values: mixed}>  $input
     * @return array<string, Field>
     *
     * @throws ValidationException|JsonException|BindingResolutionException
     */
    public function validate(array $input): array
    {
        return $this->validator()->validate($input);
    }

    /**
     * Return an AttributeValidator for this entity.
     * Registers any model-defined unique scopes before returning the instance.
     */
    protected function validator(): AttributeValidator
    {
        foreach (static::attributeUniqueScopes() as $code => $callback) {
            AttributeValidator::registerUniqueScope($this->attributeEntityType(), $code, $callback);
        }

        return new AttributeValidator($this, $this->attributeManager);
    }

    /**
     * Return available attribute definitions for this entity.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     */
    public function availableAttributes(array $params = []): Collection
    {
        return $this->availableAttributesQuery($params)?->get() ?? collect();
    }

    /**
     * Return a query builder for available attributes (global or by relation).
     *
     * @param  array<string, mixed>  $params
     */
    public function availableAttributesQuery(array $params = []): ?Builder
    {
        if (($model = static::attributeScopeModel()) !== null) {
            return $this->scopedAttributesQuery($params, $model);
        }

        return $this->globalAttributesQuery();
    }

    /**
     * Scope: filter by a single attribute value.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '='): Builder
    {
        if ($operator === 'tree') {
            return $this->scopeWhereAttributeTree($query, $code, $value);
        }

        $sub = $this->eav()->subquery($code, $value, $operator);

        return $sub ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub) : $query;
    }

    /**
     * Scope: filter by a single attribute using LIKE search.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeLike(Builder $query, string $code, string $value): Builder
    {
        return $this->scopeWhereAttribute($query, $code, $value, 'like');
    }

    /**
     * Scope: filter by attribute value range (inclusive).
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeBetween(Builder $query, string $code, float|int $min, float|int $max): Builder
    {
        $sub = $this->eav()->subquery($code, [$min, $max], 'between');

        return $sub ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub) : $query;
    }

    /**
     * Scope: filter by attribute value IN a set.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        $sub = $this->eav()->subquery($code, $values, 'in');

        return $sub ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub) : $query;
    }

    /**
     * Scope: apply multiple attribute conditions (AND logic).
     *
     * Each condition: ['code' => string, 'value' => mixed, 'operator' => string (optional)].
     *
     * @param  array<int, array{code: string, value: mixed, operator?: string}>  $conditions
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributes(Builder $query, array $conditions): Builder
    {
        $manager = $this->eav();

        foreach ($conditions as $condition) {
            $sub = $manager->subquery(
                $condition['code'],
                $condition['value'],
                $condition['operator'] ?? '=',
            );

            if ($sub) {
                $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub);
            }
        }

        return $query;
    }

    /**
     * Scope: find entities whose attribute equals $value, then expand to all NestedSet descendants.
     *
     * Executes two lightweight queries: one to resolve matching root IDs, one to expand the tree.
     * Falls back to exact-match filtering when the model does not use NodeTrait.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeTree(Builder $query, string $code, mixed $value): Builder
    {
        $sub = $this->eav()->subquery($code, $value, '=');

        if (! $sub) {
            return $query;
        }

        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $qualifiedKey = $model->getQualifiedKeyName();

        $matchingIds = $model->newQuery()
            ->whereIn($qualifiedKey, $sub)
            ->pluck($keyName)
            ->toArray();

        if (empty($matchingIds)) {
            return $query->whereRaw('1 = 0');
        }

        $treeQuery = $model->newQuery();

        if (method_exists($treeQuery, 'whereDescendantOrSelf')) {
            $allIds = $treeQuery
                ->where(function (Builder $q) use ($matchingIds): void {
                    foreach (array_values($matchingIds) as $i => $id) {
                        $q->whereDescendantOrSelf($id, $i === 0 ? 'and' : 'or');
                    }
                })
                ->pluck($keyName)
                ->toArray();
        } else {
            $allIds = $matchingIds;
        }

        if (empty($allIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($qualifiedKey, $allIds);
    }

    /**
     * Whether this entity should inherit attributes from its parent.
     * Override in models that support attribute inheritance.
     */
    public function shouldInheritAttributes(): bool
    {
        return false;
    }

    /**
     * Default filter parameters passed to availableAttributesQuery().
     * Override in models that use byRelation scope (e.g. return category IDs for Product).
     */
    public function attributeParameters(): array
    {
        return [];
    }

    /**
     * Relation that provides available attributes for other entities scoped by this model.
     * Override in models that act as attribute scope providers (e.g. Category for Product).
     */
    public function available_attributes(): ?BelongsToMany
    {
        return null;
    }

    /**
     * Raw Eloquent relation to Attribute through entity_attribute pivot (with value columns).
     */
    public function attribute_relation(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('attribute'), 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    /**
     * Raw Eloquent relation to entity_attribute rows for this entity.
     */
    public function attribute_values(): MorphMany
    {
        return $this->morphMany(EavModels::class('entity_attribute'), 'entity');
    }

    /**
     * Return the FQCN of the model used to resolve relation-scoped attributes.
     * Override in models that scope attributes by a related model (e.g. Category for Product).
     *
     * @return class-string<Attributable>|null
     */
    protected static function attributeScopeModel(): ?string
    {
        return null;
    }

    /**
     * Return a query for all attributes shared globally for this entity type.
     */
    protected function globalAttributesQuery(): Builder
    {
        return EavModels::query('attribute')
            ->forEntity($this->attributeEntityType())
            ->withRelations();
    }

    /**
     * Return a query for attributes scoped through related entities (e.g. categories for products).
     *
     * @param  array<string, mixed>  $params
     * @param  class-string<Attributable>  $model
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    protected function scopedAttributesQuery(array $params, string $model): ?Builder
    {
        if (empty($params)) {
            return null;
        }

        $instance = new $model();

        // Defining the necessary columns
        $columns = ['id', 'parent_id', 'is_inherits_properties'];

        if (method_exists($instance, 'ancestors')) {
            $columns[] = '_lft';
            $columns[] = '_rgt';
        }

        $entities = $model::query()
            ->whereIn('id', $params)
            ->select($columns)
            ->get()
            ->keyBy('id');

        if ($entities->isEmpty()) {
            return null;
        }

        // Resolve inheritance
        $allEntities = app(AttributeInheritanceResolver::class)
            ->resolve($entities, $model);

        if (empty($allEntities)) {
            return null;
        }

        $entityIds = $allEntities instanceof Collection
            ? $allEntities->pluck('id')
            : collect($allEntities)->pluck('id');

        if ($entityIds->isEmpty()) {
            return null;
        }

        $relation = $instance->available_attributes();

        if ($relation === null) {
            return null;
        }

        $pivotTable = $relation->getTable();
        $foreignKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();

        return EavModels::query('attribute')
            ->whereIn('id', function ($query) use ($pivotTable, $relatedKey, $foreignKey, $entityIds): void {
                $query->from($pivotTable)
                    ->select($relatedKey)
                    ->whereIn($foreignKey, $entityIds)
                    ->distinct();
            })
            ->withRelations();
    }
}
