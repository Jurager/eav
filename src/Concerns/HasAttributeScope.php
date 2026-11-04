<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jurager\Eav\Attributes\Scope\AttributeInheritanceResolver;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Contracts\ShouldUseNestedSet;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Relations\ClosureRelation;

/**
 * Limit available attributes to an entity's related scope models.
 */
trait HasAttributeScope
{
    /**
     * Return the model class defining the attribute scope, if configured.
     *
     * @return class-string<Attributable>|null
     */
    protected static function attributeScopeModel(): ?string
    {
        return null;
    }

    /**
     * Resolve the relation name for entities that constrain available attributes.
     */
    protected function attributeScopeRelationName(): ?string
    {
        $model = static::attributeScopeModel();

        return $model !== null ? Str::camel(Str::plural(class_basename($model))) : null;
    }

    /**
     * Get the entities defining this scope, falling back to the parent when empty.
     *
     * @return Collection<int, mixed>
     */
    public function attributeScopeEntities(): Collection
    {
        $relation = $this->attributeScopeRelationName();

        if ($relation === null) {
            return collect();
        }

        // Read the relation normally so Laravel can autoload it for the collection.
        $entities = Collection::wrap($this->{$relation});

        return $entities->isNotEmpty()
            ? $entities
            : ($this->attributeParent()?->attributeScopeEntities() ?? collect());
    }

    /**
     * Collect the keys of the effective attribute-scope entities.
     */
    public function attributeScopeIds(): array
    {
        return $this->attributeScopeEntities()->map(static fn (Model $entity) => $entity->getKey())->all();
    }

    /**
     * Check whether the effective scope intersects the given roots or their descendants.
     *
     * @param  array<int>  $rootIds
     */
    public function attributeScopeMatchesTree(array $rootIds): bool
    {
        if (empty($rootIds)) {
            return true;
        }

        $entities = $this->attributeScopeEntities();

        if ($entities->isEmpty()) {
            return false;
        }

        $model = $entities->first();
        $ids = $entities->pluck($model->getKeyName())->all();

        if (! $model instanceof ShouldUseNestedSet) {
            return array_intersect($ids, $rootIds) !== [];
        }

        $query = $model->newQuery()->whereKey($ids);

        return $query->where(function (Builder $q) use ($rootIds): void {
            foreach ($rootIds as $i => $rootId) {
                $q->whereDescendantOrSelf($rootId, $i === 0 ? 'and' : 'or');
            }
        })->exists();
    }

    /**
     * Retrieve attribute definitions available in the requested scope.
     *
     * @param  array<int>  $params
     * @return Collection<int, mixed>
     */
    public function availableAttributes(array $params = []): Collection
    {
        return $this->getAvailableAttributesQuery($params)?->get() ?? collect();
    }

    /**
     * Build the query for globally available or relation-scoped attributes.
     *
     * @param  array<int>  $params
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder
    {
        if (($model = static::attributeScopeModel()) !== null) {
            return $this->scopedAttributesQuery($params, $model);
        }

        return $this->globalAttributesQuery();
    }

    /**
     * Get allowed attribute IDs; null allows all and an empty list allows none.
     *
     * @return list<int|string>|null
     */
    public function attributeIdsInScope(): ?array
    {
        $model = static::attributeScopeModel();

        if ($model === null) {
            return null;
        }

        $ids = $this->attributeScopeIds();

        if ($ids === []) {
            return [];
        }

        $resolved = app(SchemaRegistry::class)->resolveScopes(
            $model,
            $ids,
            static fn (array $missing): array => app(AttributeInheritanceResolver::class)
                ->attributeIds($model, $missing),
            fn (): array => $this->autoloadedScopeIds(),
        );

        if ($resolved === []) {
            return [];
        }

        return array_values(array_unique(array_merge(...array_values($resolved))));
    }

    /**
     * Build the query for attributes editable on this entity rather than its parent.
     *
     * @param  array<int>  $params
     */
    public function getEditableAttributesQuery(array $params = []): ?Builder
    {
        return $this->getAvailableAttributesQuery($params)
            ?->whereHeldBy(HeldBy::of($this->isVariant()));
    }

    /**
     * Create a closure-backed relation for available attribute definitions.
     *
     * @return ClosureRelation<Attribute, $this>
     */
    public function availableAttributesRelation(Closure $resolver): ClosureRelation
    {
        return $this->closureRelation(Eav::$attributeModel, $resolver);
    }

    /**
     * Build a closure-backed relation that resolves scoped attributes for a parent.
     *
     * @return ClosureRelation<Attribute, $this>
     */
    public function closuredAttributesRelation(
        string $entityClass,
        ?Closure $scope = null,
        ?Closure $constrain = null
    ): ClosureRelation {
        $scope ??= static fn (Model $parent): array => $parent->attributeScopeSubtreeIds();

        return $this->availableAttributesRelation(
            static function (Model $parent) use ($entityClass, $scope, $constrain): ?Builder {
                $query = (new $entityClass)->getAvailableAttributesQuery($scope($parent));

                return $query !== null && $constrain !== null ? $constrain($query) : $query;
            }
        );
    }

    /**
     * Return nested-set subtree keys, or the current key for non-nested entities.
     */
    public function attributeScopeSubtreeIds(): array
    {
        if ($this instanceof ShouldUseNestedSet && $this->getAttribute($this->getLftName()) !== null && $this->getAttribute($this->getRgtName()) !== null) {
            return static::query()
                ->where($this->getLftName(), '>=', $this->getAttribute($this->getLftName()))
                ->where($this->getRgtName(), '<=', $this->getAttribute($this->getRgtName()))
                ->pluck($this->getKeyName())
                ->all();
        }

        return [$this->getKey()];
    }

    /**
     * Indicate whether attribute inheritance is enabled by this entity.
     */
    public function shouldInheritAttributes(): bool
    {
        return false;
    }

    /**
     * List the columns needed by the attribute inheritance resolver.
     */
    public function getInheritanceColumns(): array
    {
        return [$this->getKeyName(), $this->inheritanceParentKey()];
    }

    /**
     * The column naming the parent this entity inherits attributes from.
     */
    public function inheritanceParentKey(): string
    {
        return 'parent_id';
    }

    /**
     * Expose the pivot relation that assigns attributes to a scope entity.
     */
    public function attributeScopeRelation(): ?BelongsToMany
    {
        return $this->assignedAttributes();
    }

    /**
     * Query attributes belonging to the current entity type without scope filtering.
     */
    protected function globalAttributesQuery(): Builder
    {
        return Eav::$attributeModel::query()
            ->forEntity($this->getEntityType())
            ->ordered();
    }

    /**
     * Query attributes assigned to scope entities and their contributing ancestors.
     */
    protected function scopedAttributesQuery(array $params, string $model): ?Builder
    {
        $relation = (new $model)->attributeScopeRelation();

        if ($params === [] || $relation === null) {
            return null;
        }

        $resolver = app(AttributeInheritanceResolver::class);
        $entityIds = $resolver->resolve($resolver->entities($model, $params), $model)->map->getKey();

        if ($entityIds->isEmpty()) {
            return null;
        }

        $attributes = Eav::$attributeModel::query();

        return $attributes
            ->whereIn($attributes->getModel()->getQualifiedKeyName(), $relation->newPivotStatement()
                ->select($relation->getRelatedPivotKeyName())
                ->whereIn($relation->getForeignPivotKeyName(), $entityIds))
            ->forEntity($this->getEntityType())
            ->ordered();
    }

    /**
     * Collect scope IDs from loaded sibling models.
     *
     * @return list<int|string>
     */
    private function autoloadedScopeIds(): array
    {
        $relation = $this->attributeScopeRelationName();
        $context = $this->relationAutoloadContext;

        if ($relation === null || ! is_iterable($context)) {
            return [];
        }

        $ids = [];

        foreach ($context as $model) {
            if (
                $model === $this
                || ! $model instanceof Model
                || $model::class !== static::class
                || ! $model->relationLoaded($relation)
            ) {
                continue;
            }

            foreach (Collection::wrap($model->getRelation($relation)) as $entity) {
                $ids[] = $entity->getKey();
            }
        }

        return $ids;
    }
}
