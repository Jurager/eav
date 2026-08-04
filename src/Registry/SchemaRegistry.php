<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

class SchemaRegistry
{
    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @var array<string, Collection<TKey, TValue>>
     */
    private array $schemas = [];

    /**
     * @return Collection<TKey, TValue>
     * @param callable(): Collection<TKey, TValue> $loader
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

        foreach ($this->cache as $key => $_) {
            if (str_starts_with($key, $prefix)) {
                unset($this->schemas[$key]);
            }
        }
    }
}
