<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

class SchemaRegistry
{
    /** @var array<string, Collection<array-key, mixed>> */
    private array $schemas = [];

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  callable(): Collection<TKey, TValue>  $loader
     * @return Collection<TKey, TValue>
     */
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

        $prefix = "{$entityType}:";

        $this->schemas = array_filter($this->schemas, static fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }
}
