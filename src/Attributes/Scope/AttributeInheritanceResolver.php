<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Scope;

use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\ShouldUseNestedSet;
use Jurager\Eav\Exceptions\CircularInheritanceException;

/**
 * Resolve inherited attribute scopes across entity hierarchies.
 */
class AttributeInheritanceResolver
{
    /**
     * Maximum number of entity IDs processed per attributeIds() batch.
     */
    private const int BATCH_SIZE = 500;

    /** @param  int  $maxDepth  Most parent levels walked before a chain is taken for circular. */
    public function __construct(
        #[Config('eav.max_inheritance_depth', 10)] private readonly int $maxDepth = 10,
    ) {}

    /**
     * Return the supplied entities together with ancestors that contribute inherited attributes.
     *
     * @param  Collection<int, Model>  $entities
     * @return Collection<int, Model>
     *
     * @throws CircularInheritanceException
     */
    public function resolve(Collection $entities, string $model): Collection
    {
        return self::unique($entities->values()->merge(collect($this->chains($entities, $model))->flatten(1)));
    }

    /**
     * Resolve each entity’s inheritance chain independently.
     *
     * @param  Collection<int, Model>  $entities
     * @return array<int|string, list<int|string>> Keyed by entity key: the entity first, then its ancestors.
     *
     * @throws CircularInheritanceException
     */
    public function resolveEach(Collection $entities, string $model): array
    {
        $lineage = [];

        foreach ($this->chains($entities, $model) as $key => $chain) {
            $lineage[$key] = [$key, ...$chain->map(static fn (Model $ancestor) => $ancestor->getKey())->all()];
        }

        return $lineage;
    }

    /**
     * Resolve available attribute IDs for each requested entity of the given model class.
     *
     * @param  class-string  $model
     * @param  list<int|string>  $ids
     * @return array<int|string, list<int|string>> Keyed by entity key; keys that name no entity are left out.
     *
     * @throws CircularInheritanceException
     */
    public function attributeIds(string $model, array $ids): array
    {
        $relation = (new $model)->attributeScopeRelation();

        if ($relation === null) {
            return [];
        }

        $resolved = [];

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $lineage = $this->resolveEach($this->entities($model, $chunk), $model);

            if ($lineage === []) {
                continue;
            }

            $links = $this->links($relation, array_merge(...array_values($lineage)));

            foreach ($lineage as $id => $sources) {
                $linked = array_map(static fn ($source): array => $links[$source] ?? [], $sources);
                $resolved[$id] = array_values(array_unique(array_merge(...$linked)));
            }
        }

        return $resolved;
    }

    /**
     * Fetch entities by key with only the columns needed to resolve inheritance.
     *
     * @param  class-string<Model>  $model
     * @param  array<int|string>  $ids
     * @return Collection<int|string, Model>
     */
    public function entities(string $model, array $ids): Collection
    {
        $instance = new $model;

        return $model::query()
            ->select(self::columns($instance))
            ->whereKey($ids)
            ->get()
            ->keyBy($instance->getKeyName());
    }

    /**
     * Get each entity's contributing ancestors, nearest first.
     *
     * @param  Collection<int, Model>  $entities
     * @return array<int|string, Collection<int, Model>>
     *
     * @throws CircularInheritanceException
     */
    protected function chains(Collection $entities, string $model): array
    {
        $toInherit = $entities->filter->shouldInheritAttributes();
        $nested = $toInherit->first() instanceof ShouldUseNestedSet;

        if ($nested) {
            $toInherit = $toInherit->filter(self::hasBounds(...));
        }

        $ancestors = match (true) {
            $toInherit->isEmpty() => collect(),
            $nested => $this->nestedSetAncestors($toInherit, $model),
            default => $this->parentKeyAncestors($toInherit, $model),
        };

        $chains = [];

        foreach ($entities as $key => $entity) {
            $chains[$entity->getKey()] = $toInherit->has($key)
                ? $this->walkInheritanceChain($entity, $nested ? $this->enclosing($entity, $ancestors) : $ancestors)
                : collect();
        }

        return $chains;
    }

    /**
     * Fetch all strict nested-set ancestors enclosing at least one supplied entity.
     *
     * @param  Collection<int, Model&ShouldUseNestedSet>  $entities  Entities with both bounds set.
     * @return Collection<int|string, Model>
     */
    protected function nestedSetAncestors(Collection $entities, string $model): Collection
    {
        /** @var Model&ShouldUseNestedSet $instance */
        $instance = new $model;
        [$lft, $rgt] = [$instance->getLftName(), $instance->getRgtName()];

        return $model::query()
            ->where(function ($query) use ($entities, $lft, $rgt) {
                foreach ($entities as $entity) {
                    $query->orWhere(
                        fn ($q) => $q
                            ->where($lft, '<', $entity->getAttribute($lft))
                            ->where($rgt, '>', $entity->getAttribute($rgt))
                    );
                }
            })
            ->select(self::columns($instance))
            ->get()
            ->keyBy($instance->getKeyName());
    }

    /**
     * Fetch parent ancestors one level at a time, keyed by entity key.
     *
     * @return Collection<int|string, Model>
     *
     * @throws CircularInheritanceException
     */
    protected function parentKeyAncestors(Collection $toInherit, string $model): Collection
    {
        $instance = new $model;
        $parentKey = $instance->inheritanceParentKey();

        $currentIds = $toInherit->pluck($parentKey)->filter()->unique();
        $allParents = collect();
        $maxDepth = $this->maxDepth;
        $remaining = $maxDepth;

        while ($currentIds->isNotEmpty() && $remaining-- > 0) {
            $parents = $model::query()
                ->whereKey($currentIds->all())
                ->select(self::columns($instance))
                ->get()
                ->keyBy($instance->getKeyName());

            if ($parents->isEmpty()) {
                break;
            }

            // Preserve keys: merge() would reindex numeric keys and break the subsequent diff.
            $allParents = $allParents->union($parents);

            $currentIds = $parents
                ->filter(fn ($p) => $p->shouldInheritAttributes() && $p->getAttribute($parentKey))
                ->pluck($parentKey)
                ->unique()
                ->diff($allParents->keys());
        }

        if ($currentIds->isNotEmpty()) {
            throw CircularInheritanceException::maxDepthExceeded($model, $currentIds->all(), $maxDepth);
        }

        $this->assertAcyclic($allParents, $model, $maxDepth);

        return $allParents;
    }

    /**
     * Detect cycles among already-loaded parent entities.
     *
     * @param  Collection<int|string, Model>  $parents  Keyed by entity key.
     *
     * @throws CircularInheritanceException
     */
    protected function assertAcyclic(Collection $parents, string $model, int $maxDepth): void
    {
        $parentKey = (new $model)->inheritanceParentKey();

        foreach ($parents as $parent) {
            $seen = [];

            for (
                $node = $parent;
                $node?->shouldInheritAttributes() && $node->getAttribute($parentKey);
                $node = $parents->get($node->getAttribute($parentKey))
            ) {
                if (isset($seen[$node->getKey()])) {
                    throw CircularInheritanceException::maxDepthExceeded($model, [$node->getKey()], $maxDepth);
                }

                $seen[$node->getKey()] = true;
            }
        }
    }

    /**
     * Keep only ancestors whose nested-set bounds strictly enclose the entity.
     *
     * @param  Collection<int|string, Model>  $ancestors
     * @return Collection<int|string, Model>
     */
    protected function enclosing(Model&ShouldUseNestedSet $entity, Collection $ancestors): Collection
    {
        [$lft, $rgt] = [$entity->getLftName(), $entity->getRgtName()];

        return $ancestors->filter(static fn (Model $ancestor) => $ancestor->getAttribute($lft) < $entity->getAttribute($lft)
            && $ancestor->getAttribute($rgt) > $entity->getAttribute($rgt));
    }

    /**
     * Read attribute IDs linked to each entity through the scope relation’s pivot table.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, list<int|string>>
     */
    protected function links(BelongsToMany $relation, array $ids): array
    {
        $foreignKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();
        $links = [];

        foreach ($relation->newPivotStatement()
                     ->whereIn($foreignKey, array_values(array_unique($ids)))
                     ->get([$foreignKey, $relatedKey]) as $row) {
            $links[$row->{$foreignKey}][] = $row->{$relatedKey};
        }

        return $links;
    }

    /**
     * Follow the preloaded parent chain until a missing, visited, or non-inheriting parent.
     */
    protected function walkInheritanceChain(Model $entity, Collection $ancestors): Collection
    {
        $parentKey = $entity->inheritanceParentKey();
        $result = collect();
        $currentId = $entity->getAttribute($parentKey);
        $visited = [$entity->getKey() => true];

        while (
            $currentId
            && $ancestors->has($currentId)
            && ! isset($visited[$currentId])
        ) {
            $visited[$currentId] = true;
            $ancestor = $ancestors->get($currentId);
            $result->push($ancestor);

            if (! $ancestor->shouldInheritAttributes()) {
                break;
            }

            $currentId = $ancestor->getAttribute($parentKey);
        }

        return $result;
    }

    /** @return list<string> The columns inheritance is resolved with, bounds included for a nested set. */
    private static function columns(Model $instance): array
    {
        $columns = $instance->getInheritanceColumns();

        if ($instance instanceof ShouldUseNestedSet) {
            array_push($columns, $instance->getLftName(), $instance->getRgtName());
        }

        return array_values(array_unique($columns));
    }

    private static function hasBounds(Model&ShouldUseNestedSet $entity): bool
    {
        return $entity->getAttribute($entity->getLftName()) !== null && $entity->getAttribute($entity->getRgtName()) !== null;
    }

    /** @param  Collection<int, Model>  $entities */
    private static function unique(Collection $entities): Collection
    {
        return $entities->unique(static fn (Model $entity) => $entity->getKey())->values();
    }
}
