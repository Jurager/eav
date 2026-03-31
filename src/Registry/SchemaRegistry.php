<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

/**
 * Process-level cache of attribute schema collections, keyed by entity type and parameters.
 */
class SchemaRegistry
{
    /** @var array<string, Collection> */
    private array $schemas = [];

    /**
     * Return the cached schema for $key, loading it via $loader on first access.
     *
     * @return Collection<int, mixed>
     */
    public function resolve(string $key, callable $loader): Collection
    {
        return $this->schemas[$key] ??= $loader();
    }

    /**
     * Forget cached schemas for a specific entity type, or all schemas when null.
     */
    public function forget(?string $entityType = null): void
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
