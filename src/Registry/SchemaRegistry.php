<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

class SchemaRegistry
{
    /** @var array<string, Collection> */
    private array $schemas = [];

    /** Resolve the cached schema or load it if not present. */
    public function resolve(string $key, callable $loader): Collection
    {
        return $this->schemas[$key] ??= $loader();
    }

    /** Clear cached schemas for a specific entity type, or all when null. */
    public function forget(?string $entityType = null): void
    {
        if ($entityType === null) {
            $this->schemas = [];
            return;
        }

        foreach (array_keys($this->schemas) as $key) {
            if (str_starts_with((string) $key, "{$entityType}:")) {
                unset($this->schemas[$key]);
            }
        }
    }
}
