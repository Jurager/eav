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

    /** Cached AttributeManager instance for this model. */
    protected ?AttributeManager $attributeManager = null;

    /** Boot trait attributes. */
    public static function bootHasAttributes(): void
    {
        static::resolveRelationUsing('attribute_values', fn ($model) => $model->attributeValues());

        static::resolveRelationUsing('available_attributes', fn (Model $model) => $model->availableAttributesRelation(
            fn (Model $entity) => $entity->getAvailableAttributesQuery($entity->getEavScopes()),
        ));
    }

    /**
     * Declare scoped uniqueness for specific attributes.
     *
     * @return array<string, callable>
     */
    public static function attributeUniqueScopes(): array
    {
        return [];
    }

    /**
     * Get model FQCN used to resolve relation-scoped attributes.
     *
     * @return class-string<Attributable>|null
     */
    protected static function attributeScopeModel(): ?string
    {
        return null;
    }

    /** Get cached attribute manager instance. */
    public function eav(): AttributeManager
    {
        return $this->attributeManager ??= AttributeManager::for($this);
    }

    /** Fallback to EAV attribute reading for undefined properties. */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($value !== null || ! is_string($key) || $key === '') {
            return $value;
        }

        if ($this->isRealColumn($key) || array_key_exists($key, $this->relations)) {
            return $value;
        }

        return $this->eav()->value($key);
    }

    /** Fallback to EAV attribute assignment for undefined properties. */
    public function setAttribute($key, $value)
    {
        if (
            is_string($key) && $key !== ''
            && ! $this->hasSetMutator($key)
            && ! $this->hasAttributeSetMutator($key)
            && ! $this->isRelation($key)
            && ! $this->isRealColumn($key)
            && $this->eav()->field($key) !== null
        ) {
            $this->eav()->set($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /** Check if key is a real database column. */
    private function isRealColumn(string $key): bool
    {
        static $columns = [];

        $table = $this->getTable();

        return in_array($key, $columns[$table] ??= $this->getConnection()->getSchemaBuilder()->getColumnListing($table), true);
    }

    /**
     * Validate attribute input and return parsed fields.
     *
     * @param array<int, array{code: string, values: mixed}> $input
     * @return array<string, Field>
     *
     * @throws ValidationException|JsonException|BindingResolutionException
     */
    public function validate(array $input): array
    {
        return $this->validator()->validate($input);
    }

    /**
     * Get available attribute definitions.
     *
     * @param array<int> $params
     * @return Collection<int, mixed>
     */
    public function availableAttributes(array $params = []): Collection
    {
        return $this->getAvailableAttributesQuery($params)?->get() ?? collect();
    }

    /**
     * Get available attributes query builder.
     *
     * @param array<int> $params
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder
    {
        if (($model = static::attributeScopeModel()) !== null) {
            return $this->scopedAttributesQuery($params, $model);
        }

        return $this->globalAttributesQuery();
    }

    /** Expose available attributes as closure relation. */
    public function availableAttributesRelation(Closure $resolver): ClosureRelation
    {
        return $this->closureRelation(Eav::$attributeModel, $resolver);
    }

    /** Define closure relation for scoped attributes. */
    public function closuredAttributesRelation(string $entityClass, ?Closure $scope = null, ?Closure $constrain = null): ClosureRelation
    {
        $scope ??= static fn (Model $parent): array => $parent->attributeScopeSubtreeIds();

        return $this->availableAttributesRelation(static function (Model $parent) use ($entityClass, $scope, $constrain): ?Builder {
            $query = (new $entityClass())->getAvailableAttributesQuery($scope($parent));

            return $query !== null && $constrain !== null ? $constrain($query) : $query;
        });
    }

    /** Get nested-set subtree IDs for attribute scope. */
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

    /** Get attribute filter query builder. */
    protected function attributeFilterBuilder(): AttributeQueryBuilder
    {
        return AttributeManager::for($this->getEavEntityType())->builder();
    }

    /** Filter by single attribute value. */
    public function scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '='): Builder
    {
        if ($operator === 'tree') {
            return $this->scopeWhereAttributeTree($query, $code, $value);
        }

        return $this->applyAttributeSubquery($query, $code, $value, $operator);
    }

    /** Filter by attribute value using LIKE operator. */
    public function scopeWhereAttributeLike(Builder $query, string $code, string $value): Builder
    {
        return $this->scopeWhereAttribute($query, $code, $value, 'like');
    }

    /** Filter by attribute value range (inclusive). */
    public function scopeWhereAttributeBetween(Builder $query, string $code, float|int $min, float|int $max): Builder
    {
        return $this->applyAttributeSubquery($query, $code, [$min, $max], 'between');
    }

    /** Filter by attribute value IN a set. */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        return $this->applyAttributeSubquery($query, $code, $values, 'in');
    }

    /** Filter by multiple attribute conditions. */
    public function scopeWhereAttributes(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $condition) {
            $this->applyAttributeSubquery($query, $condition['code'], $condition['value'], $condition['operator'] ?? '=');
        }

        return $query;
    }

    /** Constrain query to keys matching attribute-filter subquery. */
    private function applyAttributeSubquery(Builder $query, string $code, mixed $value, string $operator): Builder
    {
        $sub = $this->attributeFilterBuilder()->subquery($code, $value, $operator);

        if (! $sub) {
            return $query;
        }

        return $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub);
    }

    /** Filter by attribute value and expand to NestedSet descendants. */
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

        if (empty($allIds)) {
            return $query->whereKey([]);
        }

        return $query->whereIn($model->getQualifiedKeyName(), $allIds);
    }

    /** Resolve entity keys matched by attribute-filter subquery. */
    private function matchingAttributeIds(Model $model, Builder $sub): array
    {
        return $model->newQuery()
            ->whereIn($model->getQualifiedKeyName(), $sub)
            ->pluck($model->getKeyName())
            ->all();
    }

    /** Expand root IDs to all NestedSet descendants. */
    private function expandToDescendants(Model $model, array $ids): array
    {
        $treeQuery = $model->newQuery();

        if (! method_exists($treeQuery, 'whereDescendantOrSelf')) {
            return $ids;
        }

        $indexedIds = array_values($ids);

        return $treeQuery
            ->where(function (Builder $q) use ($indexedIds): void {
                foreach ($indexedIds as $i => $id) {
                    $q->whereDescendantOrSelf($id, $i === 0 ? 'and' : 'or');
                }
            })
            ->pluck($model->getKeyName())
            ->all();
    }

    /** Determine if entity should inherit EAV attributes. */
    public function shouldInheritEavAttributes(): bool
    {
        return false;
    }

    /** Get columns required for inheritance resolution. */
    public function getEavInheritanceColumns(): array
    {
        return ['id', 'parent_id'];
    }

    /** Get default scope parameters for available attributes query. */
    public function getEavScopes(): array
    {
        return [];
    }

    /** Define Eloquent relation to Attribute through pivot. */
    public function assignedAttributes(): MorphToMany
    {
        return $this->morphToMany(Eav::$attributeModel, 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    /** Define Eloquent relation to entity_attribute values. */
    public function attributeValues(): MorphMany
    {
        return $this->morphMany(Eav::$entityAttributeModel, 'entity');
    }

    /** Define pivot relation used to resolve scoped attributes. */
    public function attributeScopeRelation(): ?BelongsToMany
    {
        return $this->assignedAttributes();
    }

    /** Get attribute validator instance. */
    protected function validator(): AttributeValidator
    {
        return new AttributeValidator($this, $this->attributeManager);
    }

    /** Get query for globally shared attributes. */
    protected function globalAttributesQuery(): Builder
    {
        return Eav::$attributeModel::query()
            ->forEntity($this->getEavEntityType())
            ->withRelations();
    }

    /** Get query for attributes scoped through related entities. */
    protected function scopedAttributesQuery(array $params, string $model): ?Builder
    {
        if (empty($params)) {
            return null;
        }

        $instance = new $model();
        $entities = $this->loadInheritanceEntities($model, $instance, $params);

        if (empty($entities)) {
            return null;
        }

        $entityIds = $this->resolveInheritedEntityIds($entities, $model);

        if (empty($entityIds)) {
            return null;
        }

        $relation = $instance->attributeScopeRelation();

        if ($relation === null) {
            return null;
        }

        return $this->attributeScopeSubquery($relation, $entityIds);
    }

    /** Load entities required for inheritance resolution. */
    private function loadInheritanceEntities(string $model, object $instance, array $params): ?Collection
    {
        $columns = $instance->getEavInheritanceColumns();

        if ($instance instanceof Hierarchical) {
            array_push($columns, '_lft', '_rgt');
        }

        $entities = $model::query()
            ->select(array_unique($columns))
            ->whereIn('id', $params)
            ->get()
            ->keyBy('id');

        return $entities->isEmpty() ? null : $entities;
    }

    /** Resolve inherited entity IDs via configured resolver. */
    private function resolveInheritedEntityIds(Collection $entities, string $model): ?Collection
    {
        $allEntities = app(AttributeInheritanceResolver::class)->resolve($entities, $model);

        if (empty($allEntities)) {
            return null;
        }

        // collect() safely wraps arrays or returns the Collection directly
        $entityIds = collect($allEntities)->pluck('id');

        return $entityIds->isEmpty() ? null : $entityIds;
    }

    /** Build attribute-scope subquery for pivot rows. */
    private function attributeScopeSubquery(BelongsToMany $relation, Collection $entityIds): Builder
    {
        $pivotTable = $relation->getTable();
        $foreignKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();

        return Eav::$attributeModel::query()
            ->whereIn('id', function ($query) use ($pivotTable, $relatedKey, $foreignKey, $entityIds): void {
                $query->from($pivotTable)
                    ->select($relatedKey)
                    ->whereIn($foreignKey, $entityIds)
                    ->distinct();
            })
            ->withRelations();
    }
}