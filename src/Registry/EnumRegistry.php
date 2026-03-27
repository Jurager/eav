<?php

namespace Jurager\Eav\Registry;

/**
 * Process-level cache of valid enum IDs keyed by attribute_id.
 *
 * Registered as a singleton so Octane workers share one instance per process
 * instead of using a static class property that cannot be reset by the container.
 * Flushed by AttributeEnumObserver on every enum mutation.
 */
class EnumRegistry
{
    /** @var array<int, array<int, true>> */
    private array $cache = [];

    public function has(int $attributeId): bool
    {
        return isset($this->cache[$attributeId]);
    }

    /**
     * @return array<int, true>
     */
    public function get(int $attributeId): array
    {
        return $this->cache[$attributeId] ?? [];
    }

    /**
     * @param  array<int, true>  $ids
     */
    public function put(int $attributeId, array $ids): void
    {
        $this->cache[$attributeId] = $ids;
    }

    /**
     * Flush cached enum IDs for a specific attribute, or all attributes when null.
     */
    public function flush(?int $attributeId = null): void
    {
        if ($attributeId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$attributeId]);
        }
    }
}
