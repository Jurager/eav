<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Contracts\ShouldUseNestedSet;
use Jurager\Eav\Eav;
use Jurager\Eav\Relations\ClosureRelation;
use Jurager\Eav\Support\AttributeInheritanceResolver;

trait HasInheritedAttributes
{
    /**
     * Get the model class used to resolve relation-scoped attributes.
     *
     * @return class-string<\Jurager\Eav\Contracts\Attributable>|null
     */
    protected static function attributeScopeModel(): ?string
    {
        return null;
    }

    /**
     * Name of the relation whose related IDs scope this entity's available
     * attributes. Defaults to the pluralized, camelCase basename of
     * `attributeScopeModel()` (`Category` → `categories`) — override when the
     * relation isn't named by that convention.
     */
    protected function attributeScopeRelationName(): ?string
    {
        $model = static::attributeScopeModel();

        return $model !== null ? Str::camel(Str::plural(class_basename($model))) : null;
    }

    /**
     * Name of the relation holding the parent entity this one is a variant of.
     *
     * Declaring it turns the entity into a child: it resolves its attribute scope through the
     * parent, inherits the parent's values and keeps only the attributes it may hold itself.
     */
    protected function attributeParentRelationName(): ?string
    {
        return null;
    }

    /** Get the parent entity this one inherits its scope and values from. */
    public function attributeParent(): ?Attributable
    {
        $relation = $this->attributeParentRelationName();

        // A root entity has no parent — reading the relation for an empty key buys nothing.
        $foreignKey = $this->attributeParentRelation()?->getForeignKeyName();

        if ($relation === null || ($foreignKey !== null && $this->getAttribute($foreignKey) === null)) {
            return null;
        }

        $this->loadMissing($relation);

        return $this->{$relation} instanceof Attributable ? $this->{$relation} : null;
    }

    /** Determine if the entity is a variant of a parent entity. */
    public function isVariant(): bool
    {
        return $this->attributeParent() !== null;
    }

    /** Get the relation pointing at the parent entity, when the model declares one. */
    public function attributeParentRelation(): ?BelongsTo
    {
        $relation = $this->attributeParentRelationName();
        $instance = $relation !== null && method_exists($this, $relation) ? $this->{$relation}() : null;

        return $instance instanceof BelongsTo ? $instance : null;
    }

    /**
     * Get the related entities whose attributes make up this entity's scope.
     *
     * A variant carries no scope of its own — a product offer belongs to the categories of its
     * model — so an empty scope falls back to the parent's.
     *
     * @return Collection<int, mixed>
     */
    public function attributeScopeEntities(): Collection
    {
        $relation = $this->attributeScopeRelationName();

        if ($relation === null) {
            return collect();
        }

        $this->loadMissing($relation);

        $entities = Collection::wrap($this->{$relation});

        return $entities->isNotEmpty()
            ? $entities
            : ($this->attributeParent()?->attributeScopeEntities() ?? collect());
    }

    /** Get the IDs of the related scope entities used to resolve inherited attributes. */
    public function attributeScopeIds(): array
    {
        return $this->attributeScopeEntities()->pluck('id')->all();
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

    /**
     * Get the query for the attributes the entity fills in itself.
     *
     * The schema carries every attribute in scope, including the ones a variant only reads off its
     * parent; this narrows it down to the side that holds the value.
     *
     * @param array<int> $params
     */
    public function getEditableAttributesQuery(array $params = []): ?Builder
    {
        $query = $this->getAvailableAttributesQuery($params);

        return $this->isVariant() ? $query?->whereHeldByChild() : $query?->whereHeldByParent();
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

    /** Determine if entity should inherit attributes. */
    public function shouldInheritAttributes(): bool
    {
        return false;
    }

    /** Get columns required for inheritance resolution. */
    public function getInheritanceColumns(): array
    {
        return ['id', 'parent_id'];
    }

    /** Define pivot relation used to resolve scoped attributes. */
    public function attributeScopeRelation(): ?BelongsToMany
    {
        return $this->assignedAttributes();
    }

    /** Get query for globally shared attributes. */
    protected function globalAttributesQuery(): Builder
    {
        return Eav::$attributeModel::query()
            ->forEntity($this->getEntityType())
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
        $columns = $instance->getInheritanceColumns();

        if ($instance instanceof ShouldUseNestedSet) {
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
