<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;

class SchemaRegistry
{
    private int $revision = 0;

    /** @var array<string, Collection<array-key, mixed>> Cached schemas indexed by their registry key. */
    private array $schemasByKey = [];

    /** @var array<string, array<int|string, list<int|string>>> Available attribute IDs per scope entity, grouped by model class. */
    private array $scopeAttributes = [];

    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * Resolve a schema once and reuse it for subsequent requests with the same key.
     *
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
     * Resolve available attribute IDs for the specified scope entities.
     *
     * @param  list<int|string>  $ids
     * @param  callable(list<int|string>): array<int|string, list<int|string>>  $loader  Receives IDs not yet cached.
     * @param  (callable(): iterable<int|string>)|null  $alongside  Supplies additional IDs only when a requested ID is uncached.
     * @return array<int|string, list<int|string>> Available attribute IDs keyed by the requested scope entity IDs.
     */
    public function resolveScopes(string $model, array $ids, callable $loader, ?callable $alongside = null): array
    {
        $cached = $this->scopeAttributes[$model] ?? [];
        $missing = array_diff_key(array_flip($ids), $cached);

        if ($missing !== []) {
            $missing += array_diff_key(
                array_flip($alongside !== null ? [...$alongside()] : []),
                $cached,
            );

            $loaded = $loader(array_keys($missing));

            // An empty list is a valid-cached result for a scope with no attributes.
            foreach (array_keys($missing) as $id) {
                $cached[$id] = array_values($loaded[$id] ?? []);
            }

            $this->scopeAttributes[$model] = $cached;
        }

        return array_intersect_key($cached, array_flip($ids));
    }

    /**
     * Invalidate cached schemas for one entity type, or all types when null.
     */
    public function forget(?string $entityType = null): void
    {
        $this->revision++;
        $this->scopeAttributes = [];

        if ($entityType === null) {
            $this->schemasByKey = [];

            return;
        }

        $prefix = "{$entityType}:";

        $this->schemasByKey = array_filter(
            $this->schemasByKey,
            static fn (string $key): bool => ! str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
