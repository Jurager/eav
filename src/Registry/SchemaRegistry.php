<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

class SchemaRegistry
{
    /** @var array<string, Collection<array-key, mixed>> */
    private array $schemasByKey = [];

    /** @var array<string, Collection<string, mixed>> Per-key collections, keyed internally by code. */
    private array $schemasByCode = [];

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  callable(): Collection<TKey, TValue>  $loader
     * @return Collection<TKey, TValue>
     */
    public function resolve(string $key, callable $loader): Collection
    {
        return $this->schemasByKey[$key] ??= $loader();
    }

    /**
     * Resolve entries for the given codes under $key, growing what's cached there.
     *
     * @template T of object
     *
     * @param  list<string>  $codes
     * @param  callable(list<string>): iterable<T>  $loader  Called with only the codes not yet cached; each item it returns must expose a public `code`.
     * @return Collection<int, T>
     */
    public function resolveCodes(string $key, array $codes, callable $loader): Collection
    {
        $cached = $this->schemasByCode[$key] ??= new Collection;

        $missing = array_values(array_diff($codes, $cached->keys()->all()));

        if ($missing !== []) {
            foreach ($loader($missing) as $item) {
                $cached->put($item->code, $item);
            }
        }

        return $cached->only($codes)->values();
    }

    /** Clear cached schemas for a specific entity type, or all when null. */
    public function forget(?string $entityType = null): void
    {
        if ($entityType === null) {
            $this->schemasByKey = [];
            $this->schemasByCode = [];

            return;
        }

        $prefix = "{$entityType}:";

        $this->schemasByKey = array_filter($this->schemasByKey, static fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        $this->schemasByCode = array_filter($this->schemasByCode, static fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }
}
