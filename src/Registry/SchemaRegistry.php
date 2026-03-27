<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

/**
 * Process-level cache of attribute schema collections, keyed by entity type and parameters.
 *
 * Registered as a singleton so that Octane and queue workers can reset it between
 * requests via the DI container — unlike static class properties which survive restarts.
 */
class SchemaRegistry
{
    /** @var array<string, Collection> */
    private array $schemas = [];

    public function has(string $key): bool
    {
        return isset($this->schemas[$key]);
    }

    /** @return Collection|null */
    public function get(string $key): ?Collection
    {
        return $this->schemas[$key] ?? null;
    }

    /** @param Collection<int, mixed> $attributes */
    public function put(string $key, Collection $attributes): void
    {
        $this->schemas[$key] = $attributes;
    }

    /**
     * Flush cached schemas for a specific entity type, or all schemas when null.
     */
    public function flush(?string $entityType = null): void
    {
        if ($entityType === null) {
            $this->schemas = [];
        } else {
            foreach (array_keys($this->schemas) as $key) {
                if (str_starts_with($key, $entityType.':')) {
                    unset($this->schemas[$key]);
                }
            }
        }
    }
}
