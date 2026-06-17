<?php

namespace Jurager\Eav\Concerns;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Relations\AvailableAttributes;
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
 * Add attribute support to Eloquent models.
 *
 * @property Collection $assignedAttributes
 *
 * @phpstan-require-implements Attributable
 */
trait HasAttributes
{
    /**
     * Cached AttributeManager instance — one per model instance.
     */
    protected ?AttributeManager $attributeManager = null;

    public static function bootHasAttributes(): void
    {
        static::resolveRelationUsing('attribute_values', fn ($model) => $model->attributeValues());

        // The entity attribute schema is implemented as an eager-loaded relationship (scope + inheritance).
        static::resolveRelationUsing('available_attributes', fn (Model $model) => $model->availableAttributesRelation(
            fn (Model $entity) => $entity->availableAttributesQuery($entity->attributeParameters()),
        ));
    }

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
     * @param array<string, mixed> $params
     * @return Collection<int, mixed>
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function availableAttributes(array $params = []): Collection
    {
        return $this->availableAttributesQuery($params)?->get() ?? collect();
    }

    /**
     * Return a query builder for available attributes (global or by relation).
     *
     * @param array<string, mixed> $params
     * @return Builder|null
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function availableAttributesQuery(array $params = []): ?Builder
    {
        if (($model = static::attributeScopeModel()) !== null) {
            return $this->scopedAttributesQuery($params, $model);
        }

        return $this->globalAttributesQuery();
    }

    /**
     * Expose available attributes as an eager-loadable.
     *
     * The resolver receives the parent model and returns the attribute query for it
     * (typically built from {@see availableAttributesQuery()}, optionally constrained,
     * e.g. filterable/searchable). This makes the applicable attribute schema — with
     * nested-set scope and inheritance — available via `include`, which a plain
     * `belongsToMany` cannot express.
     *
     * @param  Closure(Model): (Builder|null)  $resolver
     */
    public function availableAttributesRelation(Closure $resolver): AvailableAttributes
    {
        return new AvailableAttributes(EavModels::query('attribute'), $this, $resolver);
    }

    /**
     * Relation exposing another entity's available attributes, scoped to this model.
     * For models that act as an attribute scope of $entityClass (e.g. a category for
     * product attributes); optionally constrained (e.g. filterable).
     *
     * $scope resolves the scope entity ids for a given parent and defaults to the
     * model's nested-set subtree ({@see attributeScopeSubtreeIds()}).
     *
     * @param  class-string  $entityClass
     * @param  Closure(static): array<int>|null  $scope  Scope entity ids for a given parent.
     * @param  Closure(Builder): Builder|null  $constrain  Extra constraints on the attribute query.
     */
    public function scopedAttributesRelation(string $entityClass, ?Closure $scope = null, ?Closure $constrain = null): AvailableAttributes
    {
        $scope ??= static fn (Model $parent): array => $parent->attributeScopeSubtreeIds();

        return $this->availableAttributesRelation(static function (Model $parent) use ($entityClass, $scope, $constrain): ?Builder {
            $query = AttributeManager::for($entityClass)->query($scope($parent));

            return $query !== null && $constrain !== null ? $constrain($query) : $query;
        });
    }

    /**
     * Ids of this model's nested-set subtree (including itself) — the default attribute
     * scope when the model acts as a scope for another entity's attributes
     * ({@see scopedAttributesRelation()}). Falls back to the model's own key when it is
     * not a nested set. Override for a different scope policy.
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
     * Scope: filter by a single attribute value.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '='): Builder
    {
        if ($operator === 'tree') {
            return $this->scopeWhereAttributeTree($query, $code, $value);
        }

        $sub = $this->eav()->builder()->subquery($code, $value, $operator);

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
        $sub = $this->eav()->builder()->subquery($code, [$min, $max], 'between');

        return $sub ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub) : $query;
    }

    /**
     * Scope: filter by attribute value IN a set.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        $sub = $this->eav()->builder()->subquery($code, $values, 'in');

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
        $builder = $this->eav()->builder();

        foreach ($conditions as $condition) {
            $sub = $builder->subquery(
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
        $sub = $this->eav()->builder()->subquery($code, $value, '=');

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
     * Attribute definitions assigned to this entity via the EAV pivot (its values).
     * Scope-provider models declare their applicability pivot via {@see attributeScopeRelation()}.
     */
    public function attributes(): MorphToMany
    {
        return $this->assignedAttributes();
    }

    /**
     * Raw Eloquent relation to Attribute through entity_attribute pivot (with value columns).
     */
    public function assignedAttributes(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('attribute'), 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    /**
     * Raw Eloquent relation to entity_attribute rows for this entity.
     */
    public function attributeValues(): MorphMany
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
     * Relation through which attributes are assigned to this model when it acts as an
     * attribute scope for another entity (e.g. the category_attribute pivot for categories).
     * Used by scoped resolution to discover the pivot table/keys; the relation is not executed.
     * Defaults to {@see attributes()}; override only to decouple the scope pivot from it.
     */
    public function attributeScopeRelation(): ?BelongsToMany
    {
        return $this->attributes();
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

        $relation = $instance->attributeScopeRelation();

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
