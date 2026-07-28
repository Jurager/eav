<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Hierarchical;
use Jurager\Eav\Eav;
use Jurager\Eav\Relations\ClosureRelation;
use Jurager\Eav\Support\AttributeInheritanceResolver;

trait HasInheritedAttributes
{
    /**
     * Get model FQCN used to resolve relation-scoped attributes.
     *
     * @return class-string<\Jurager\Eav\Contracts\Attributable>|null
     */
    protected static function attributeScopeModel(): ?string
    {
        return null;
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
    
    /** Define pivot relation used to resolve scoped attributes. */
    public function attributeScopeRelation(): ?BelongsToMany
    {
        return $this->assignedAttributes();
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
