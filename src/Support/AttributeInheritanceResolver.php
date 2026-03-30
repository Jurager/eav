<?php

namespace Jurager\Eav\Support;

use Illuminate\Support\Collection;

/**
 * Resolves attribute inheritance chains for nested entity hierarchies.
 *
 * Walks the ancestor tree (using nested-set bounds or parent_id) and collects
 * all ancestor entities from which the given entities should inherit attributes.
 */
class AttributeInheritanceResolver
{
    /**
     * Expand a collection of entities by appending their attribute-inheriting ancestors.
     *
     * @param  Collection<int, mixed>  $entities  Preloaded entities (must have shouldInheritAttributes()).
     * @param  string  $model  Fully-qualified model class to query ancestors from.
     * @return Collection<int, mixed>
     */
    public function resolve(Collection $entities, string $model): Collection
    {
        $base = $entities->values();
        $toInherit = $entities->filter(fn ($e) => $e->shouldInheritAttributes());

        if ($toInherit->isEmpty()) {
            return $base;
        }

        $first = $toInherit->first();

        return method_exists($first, 'ancestors')
            ? $this->resolveWithNestedSet($toInherit, $base, $model)
            : $this->resolveWithParentId($toInherit, $base, $model);
    }

    /**
     * Collect ancestors using nested-set _lft / _rgt bounds (single query).
     *
     * @param  Collection<int, mixed>  $toInherit
     * @param  Collection<int, mixed>  $base
     */
    protected function resolveWithNestedSet(Collection $toInherit, Collection $base, string $model): Collection
    {
        $valid = $toInherit->filter(fn ($e) => isset($e->_lft, $e->_rgt));

        if ($valid->isEmpty()) {
            return $base;
        }

        $ancestors = $model::query()
            ->where(function ($query) use ($valid) {
                foreach ($valid as $entity) {
                    $query->orWhere(
                        fn ($q) => $q
                            ->where('_lft', '<', $entity->_lft)
                            ->where('_rgt', '>', $entity->_rgt)
                    );
                }
            })
            ->select(['id', '_lft', '_rgt', 'parent_id', 'is_inherits_properties'])
            ->get()
            ->keyBy('id');

        if ($ancestors->isEmpty()) {
            return $base;
        }

        $filtered = collect();

        foreach ($valid as $entity) {
            $entityAncestors = $ancestors->filter(
                fn ($a) => $a->_lft < $entity->_lft && $a->_rgt > $entity->_rgt
            );

            $filtered = $filtered->merge($this->walkInheritanceChain($entity, $entityAncestors));
        }

        return $base->merge($filtered)->unique('id');
    }

    /**
     * Collect ancestors by walking the parent_id chain level-by-level (batched queries).
     *
     * @param  Collection<int, mixed>  $toInherit
     * @param  Collection<int, mixed>  $base
     */
    protected function resolveWithParentId(Collection $toInherit, Collection $base, string $model): Collection
    {
        $currentIds = $toInherit->pluck('parent_id')->filter()->unique();
        $allParents = collect();
        $maxDepth = (int) config('eav.max_inheritance_depth', 10);

        while ($currentIds->isNotEmpty() && $maxDepth-- > 0) {
            $parents = $model::query()
                ->whereIn('id', $currentIds)
                ->select(['id', 'parent_id', 'is_inherits_properties'])
                ->get()
                ->keyBy('id');

            if ($parents->isEmpty()) {
                break;
            }

            $allParents = $allParents->merge($parents);

            $currentIds = $parents
                ->filter(fn ($p) => $p->shouldInheritAttributes() && $p->parent_id)
                ->pluck('parent_id')
                ->unique()
                ->diff($allParents->keys());
        }

        return $base->merge($allParents)->unique('id');
    }

    /**
     * Walk the parent_id chain through a preloaded ancestor map,
     * stopping when an ancestor does not inherit attributes.
     *
     * @param  Collection<int, mixed>  $ancestors  Keyed by id.
     * @return Collection<int, mixed>
     */
    protected function walkInheritanceChain(mixed $entity, Collection $ancestors): Collection
    {
        $result = collect();
        $currentId = $entity->parent_id;

        while ($currentId && $ancestors->has($currentId)) {
            $ancestor = $ancestors->get($currentId);
            $result->push($ancestor);

            if (! $ancestor->shouldInheritAttributes()) {
                break;
            }

            $currentId = $ancestor->parent_id;
        }

        return $result;
    }
}
