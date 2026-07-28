<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Attribute;

class AttributeRegistry
{
    /**
     * Cached attributes, keyed by ID, grouped by entity type.
     *
     * @var array<string, Collection<int, Attribute>>
     */
    private array $byEntityType = [];

    /**
     * Get all cached attributes for a given entity type, keyed by ID.
     *
     * Scoped per entity type (rather than caching the whole `attributes` table) because
     * a single entity type — e.g. products in a large catalog — can hold thousands of
     * rows on its own; a request touching only categories shouldn't pay to load those.
     */
    public function forEntityType(string $entityType): Collection
    {
        return $this->byEntityType[$entityType] ??= Eav::$attributeModel::query()
            ->forEntity($entityType)
            ->get()
            ->keyBy('id');
    }

    /** Determine if the registry has the given attribute for the given entity type. */
    public function has(int $id, string $entityType): bool
    {
        return $this->forEntityType($entityType)->has($id);
    }

    /** Get an attribute by its ID, scoped to the given entity type. */
    public function get(int $id, string $entityType): ?Attribute
    {
        return $this->forEntityType($entityType)->get($id);
    }

    /**
     * Clear the internal cache.
     *
     * If an entity type is provided, only that type's cache is cleared.
     * Otherwise, the entire cache for all entity types is cleared.
     */
    public function forget(?string $entityType = null): void
    {
        if ($entityType !== null) {
            unset($this->byEntityType[$entityType]);
        } else {
            $this->byEntityType = [];
        }
    }
}
