<?php

namespace Jurager\Eav\Support;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Hierarchical;
use Jurager\Eav\Exceptions\CircularInheritanceException;

/**
 * Resolves attribute inheritance through entity hierarchies.
 */
class AttributeInheritanceResolver
{
    /**
     * Expand entities with their attribute-inheriting ancestors.
     *
     * @param  Collection<int, mixed>  $entities
     * @param  string  $model
     * @return Collection<int, mixed>
     */
    public function resolve(Collection $entities, string $model): Collection
    {
        $base = $entities->values();
        $toInherit = $entities->filter(fn ($e) => $e->shouldInheritEavAttributes());

        if ($toInherit->isEmpty()) {
            return $base;
        }

        $first = $toInherit->first();

        return $first instanceof Hierarchical
            ? $this->resolveWithNestedSet($toInherit, $base, $model)
            : $this->resolveWithParentId($toInherit, $base, $model);
    }

    /** Collect ancestors via nested-set bounds in a single query. */
    protected function resolveWithNestedSet(Collection $toInherit, Collection $base, string $model): Collection
    {
        $valid = $toInherit->filter(fn ($e) => isset($e->_lft, $e->_rgt));

        if ($valid->isEmpty()) {
            return $base;
        }

        $instance = new $model();
        $columns = array_unique(array_merge($instance->getEavInheritanceColumns(), ['_lft', '_rgt']));

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
            ->select($columns)
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
     * Walk parent_id chain level-by-level in batched queries.
     *
     * @throws CircularInheritanceException
     */
    protected function resolveWithParentId(Collection $toInherit, Collection $base, string $model): Collection
    {
        $currentIds = $toInherit->pluck('parent_id')->filter()->unique();
        $allParents = collect();
        $maxDepth = (int) config('eav.max_inheritance_depth', 10);
        $remaining = $maxDepth;

        while ($currentIds->isNotEmpty() && $remaining-- > 0) {
            $parents = $model::query()
                ->whereIn('id', $currentIds)
                ->select((new $model())->getEavInheritanceColumns())
                ->get()
                ->keyBy('id');

            if ($parents->isEmpty()) {
                break;
            }

            $allParents = $allParents->merge($parents);

            $currentIds = $parents
                ->filter(fn ($p) => $p->shouldInheritEavAttributes() && $p->parent_id)
                ->pluck('parent_id')
                ->unique()
                ->diff($allParents->keys());
        }

        if ($currentIds->isNotEmpty()) {
            throw CircularInheritanceException::maxDepthExceeded($model, $currentIds->all(), $maxDepth);
        }

        return $base->merge($allParents)->unique('id');
    }

    /** Walk parent_id chain through preloaded ancestor map, stopping at non-inheriting nodes. */
    protected function walkInheritanceChain(mixed $entity, Collection $ancestors): Collection
    {
        $result = collect();
        $currentId = $entity->parent_id;

        while ($currentId && $ancestors->has($currentId)) {
            $ancestor = $ancestors->get($currentId);
            $result->push($ancestor);

            if (! $ancestor->shouldInheritEavAttributes()) {
                break;
            }

            $currentId = $ancestor->parent_id;
        }

        return $result;
    }
}
