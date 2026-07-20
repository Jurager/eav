<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Relations\ClosureRelation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Contracts\Hierarchical;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Support\AttributeQueryBuilder;
use Jurager\Eav\Support\AttributeInheritanceResolver;
use Jurager\Eav\Support\AttributeValidator;
use Jurager\Eav\Eav;

/**
 * @property Collection $assignedAttributes
 *
 * @phpstan-require-implements Attributable
 */
trait HasAttributes
{
    use HasClosureRelations;

    /**
     * Cached AttributeManager instance — one per model instance.
     */
    protected ?AttributeManager $attributeManager = null;

    public static function bootHasAttributes(): void
    {
        static::resolveRelationUsing('attribute_values', fn ($model) => $model->attributeValues());

        static::resolveRelationUsing('available_attributes', fn (Model $model) => $model->availableAttributesRelation(
            fn (Model $entity) => $entity->getAvailableAttributesQuery($entity->getEavScopes()),
        ));
    }

    /**
     * Override in models to declare scoped uniqueness for specific attributes.
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
    public static function attributeUniqueScopes(): array
    {
        return [];
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
     * Return available attribute definitions for this entity.
     *
     * @param  array<int>  $params  Scope-model IDs from getEavScopes().
     * @return Collection<int, mixed>
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function availableAttributes(array $params = []): Collection
    {
        return $this->getAvailableAttributesQuery($params)?->get() ?? collect();
    }

    /**
     * Return a query builder for available attributes (global or by relation).
     *
     * @param  array<int>  $params  Scope-model IDs from getEavScopes().
     * @return Builder|null
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder
    {
        if (($model = static::attributeScopeModel()) !== null) {
            return $this->scopedAttributesQuery($params, $model);
        }

        return $this->globalAttributesQuery();
    }

    /**
     * Expose the model's available attributes as an eager-loadable relation.
     *
     * @param  Closure(Model): (Builder|null)  $resolver
     */
    public function availableAttributesRelation(Closure $resolver): ClosureRelation
    {
        return $this->closureRelation(Eav::$attributeModel, $resolver);
    }

    /**
     * Relation exposing another entity's available attributes, scoped to this model.
     *
     * @param  class-string  $entityClass
     * @param  Closure(static): array<int>|null  $scope  Scope entity ids; defaults to the nested-set subtree.
     * @param  Closure(Builder): Builder|null  $constrain
     */
    public function closuredAttributesRelation(string $entityClass, ?Closure $scope = null, ?Closure $constrain = null): ClosureRelation
    {
        $scope ??= static fn (Model $parent): array => $parent->attributeScopeSubtreeIds();

        return $this->availableAttributesRelation(static function (Model $parent) use ($entityClass, $scope, $constrain): ?Builder {

            $query = (new $entityClass())->getAvailableAttributesQuery($scope($parent));

            return $query !== null && $constrain !== null ? $constrain($query) : $query;
        });
    }

    /**
     * Nested-set subtree ids (including self) used as the default attribute scope.
     *
     * @return array<int>
     */
    public function attributeScopeSubtreeIds(): array
    {
        if (isset($this->_lft, $this->_rgt)) {
            return static::query()
                ->where('_lft', '>=', $this->_lft)
                ->where('_rgt', '<=', $this->_rgt)
                ->pluck($this->getKeyName())
                ->all();
        }

        return [$this->getKey()];
    }

    /**
     * Builder for attribute-filter subqueries, resolved globally by entity type.
     *
     * Filtering must not depend on the instance's scoped schema (which is empty
     * for a transient/relation-scoped model, e.g. a category-scoped Product) —
     * otherwise no attribute resolves and the filter silently matches everything.
     */
    protected function attributeFilterBuilder(): AttributeQueryBuilder
    {
        return AttributeManager::for($this->getEavEntityType())->builder();
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

        return $this->applyAttributeSubquery($query, $code, $value, $operator);
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
        return $this->applyAttributeSubquery($query, $code, [$min, $max], 'between');
    }

    /**
     * Scope: filter by attribute value IN a set.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        return $this->applyAttributeSubquery($query, $code, $values, 'in');
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
        foreach ($conditions as $condition) {
            $this->applyAttributeSubquery($query, $condition['code'], $condition['value'], $condition['operator'] ?? '=');
        }

        return $query;
    }

    /** Constrain the query to keys matching an attribute-filter subquery, if one is resolvable. */
    private function applyAttributeSubquery(Builder $query, string $code, mixed $value, string $operator): Builder
    {
        $sub = $this->attributeFilterBuilder()->subquery($code, $value, $operator);

        return $sub ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub) : $query;
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

        return $allIds ? $query->whereIn($model->getQualifiedKeyName(), $allIds) : $query->whereKey([]);
    }

    /**
     * Resolve the entity keys matched by an attribute-filter subquery.
     *
     * @return array<int, int|string>
     */
    private function matchingAttributeIds(Model $model, Builder $sub): array
    {
        return $model->newQuery()
            ->whereIn($model->getQualifiedKeyName(), $sub)
            ->pluck($model->getKeyName())
            ->toArray();
    }

    /**
     * Expand root ids to all NestedSet descendants. Falls back to the root ids
     * unchanged when the model doesn't use NodeTrait.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int|string>
     */
    private function expandToDescendants(Model $model, array $ids): array
    {
        $treeQuery = $model->newQuery();

        if (! method_exists($treeQuery, 'whereDescendantOrSelf')) {
            return $ids;
        }

        return $treeQuery
            ->where(function (Builder $q) use ($ids): void {
                foreach (array_values($ids) as $i => $id) {
                    $q->whereDescendantOrSelf($id, $i === 0 ? 'and' : 'or');
                }
            })
            ->pluck($model->getKeyName())
            ->toArray();
    }

    /**
     * Whether this entity should inherit attributes from its parent.
     * Override in models that support attribute inheritance.
     */
    public function shouldInheritEavAttributes(): bool
    {
        return false;
    }

    /**
     * Columns needed when loading this entity for inheritance resolution.
     * Override to add any column that shouldInheritEavAttributes() reads from.
     *
     * @return array<string>
     */
    public function getEavInheritanceColumns(): array
    {
        return ['id', 'parent_id'];
    }

    /**
     * Default filter parameters passed to getAvailableAttributesQuery().
     * Override in models that use byRelation scope (e.g. return category IDs for Product).
     *
     * @return array<int>
     */
    public function getEavScopes(): array
    {
        return [];
    }

    /**
     * Raw Eloquent relation to Attribute through entity_attribute pivot (with value columns).
     */
    public function assignedAttributes(): MorphToMany
    {
        return $this->morphToMany(Eav::$attributeModel, 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    /**
     * Raw Eloquent relation to entity_attribute rows for this entity.
     */
    public function attributeValues(): MorphMany
    {
        return $this->morphMany(Eav::$entityAttributeModel, 'entity');
    }

    /**
     * Pivot relation used to resolve scoped attributes; defaults to {@see assignedAttributes()}.
     * Override only to decouple the scope pivot from it.
     */
    public function attributeScopeRelation(): ?BelongsToMany
    {
        return $this->assignedAttributes();
    }

    /**
     * Return an AttributeValidator for this entity.
     */
    protected function validator(): AttributeValidator
    {
        return new AttributeValidator($this, $this->attributeManager);
    }

    /**
     * Return a query for all attributes shared globally for this entity type.
     */
    protected function globalAttributesQuery(): Builder
    {
        return Eav::$attributeModel::query()
            ->forEntity($this->getEavEntityType())
            ->withRelations();
    }

    /**
     * Return a query for attributes scoped through related entities (e.g. categories for products).
     *
     * @param  array<int>  $params  Scope-model IDs from getEavScopes().
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
        $entities = $this->loadInheritanceEntities($model, $instance, $params);

        if ($entities === null) {
            return null;
        }

        $entityIds = $this->resolveInheritedEntityIds($entities, $model);

        if ($entityIds === null) {
            return null;
        }

        $relation = $instance->attributeScopeRelation();

        return $relation === null ? null : $this->attributeScopeSubquery($relation, $entityIds);
    }

    /**
     * Load the entities named by $params with just the columns inheritance resolution needs.
     *
     * @param  array<int>  $params
     */
    private function loadInheritanceEntities(string $model, object $instance, array $params): ?Collection
    {
        $columns = $instance->getEavInheritanceColumns();

        if ($instance instanceof Hierarchical) {
            $columns = array_merge($columns, ['_lft', '_rgt']);
        }

        $entities = $model::query()
            ->whereIn('id', $params)
            ->select(array_unique($columns))
            ->get()
            ->keyBy('id');

        return $entities->isEmpty() ? null : $entities;
    }

    /** Resolve inherited entity ids via the configured AttributeInheritanceResolver. */
    private function resolveInheritedEntityIds(Collection $entities, string $model): ?Collection
    {
        $allEntities = app(AttributeInheritanceResolver::class)->resolve($entities, $model);

        if (empty($allEntities)) {
            return null;
        }

        $entityIds = $allEntities instanceof Collection ? $allEntities->pluck('id') : collect($allEntities)->pluck('id');

        return $entityIds->isEmpty() ? null : $entityIds;
    }

    /** Build the attribute-scope subquery for the given entities' pivot rows. */
    private function attributeScopeSubquery(BelongsToMany $relation, Collection $entityIds): Builder
    {
        $pivotTable = $relation->getTable();
        $foreignKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();

        return Eav::$attributeModel::query()->whereIn('id', function ($query) use ($pivotTable, $relatedKey, $foreignKey, $entityIds): void {
            $query->from($pivotTable)
                ->select($relatedKey)
                ->whereIn($foreignKey, $entityIds)
                ->distinct();
        })->withRelations();
    }
}
