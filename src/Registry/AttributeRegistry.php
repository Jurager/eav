<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Attribute;

class AttributeRegistry
{
    /**
     * Attributes of each entity type, keyed by ID. Shared by every request the process serves.
     *
     * @var array<string, Collection<int, Attribute>>
     */
    private static array $byEntityType = [];

    /**
     * State of the `attributes` table each entity type was read at.
     *
     * @var array<string, string>
     */
    private static array $stamps = [];

    /**
     * IDs a lookup already failed to resolve, so a dangling reference is not queried per row.
     *
     * @var array<string, array<int, true>>
     */
    private static array $unresolved = [];

    /**
     * Entity types already checked against the table during this request.
     *
     * Held on the instance, which the container drops between requests and between queued jobs.
     * That is what keeps the check to once per entity type per request rather than once per lookup.
     *
     * @var array<string, true>
     */
    private array $checkedThisRequest = [];

    /**
     * Get all cached attributes for a given entity type, keyed by ID.
     */
    public function forEntityType(string $entityType): Collection
    {
        if (! isset(self::$byEntityType[$entityType]) || $this->changed($entityType)) {
            $this->load($entityType);
        }

        return self::$byEntityType[$entityType];
    }

    /** Determine if the registry has the given attribute for the given entity type. */
    public function has(int $id, string $entityType): bool
    {
        return $this->get($id, $entityType) !== null;
    }

    /**
     * Get an attribute by its ID, scoped to the given entity type.
     */
    public function get(int $id, string $entityType): ?Attribute
    {
        $attributes = $this->forEntityType($entityType);

        if ($attributes->has($id) || isset(self::$unresolved[$entityType][$id])) {
            return $attributes->get($id);
        }

        $attribute = Eav::$attributeModel::query()->forEntity($entityType)->find($id);

        if ($attribute === null) {
            self::$unresolved[$entityType][$id] = true;

            return null;
        }

        $attributes->put($id, $attribute);

        return $attribute;
    }

    /**
     * Clear the cache.
     */
    public function forget(?string $entityType = null): void
    {
        if ($entityType === null) {
            static::flush();

            $this->checkedThisRequest = [];

            return;
        }

        unset(
            self::$byEntityType[$entityType],
            self::$stamps[$entityType],
            self::$unresolved[$entityType],
            $this->checkedThisRequest[$entityType],
        );
    }

    /**
     * Drop everything the process holds.
     */
    public static function flush(): void
    {
        self::$byEntityType = [];
        self::$stamps = [];
        self::$unresolved = [];
    }

    /**
     * Determine if the table moved under an entity type since it was read.
     *
     * Costs one aggregate; the rows themselves are read again only when it says so.
     */
    private function changed(string $entityType): bool
    {
        if (isset($this->checkedThisRequest[$entityType])) {
            return false;
        }

        $this->checkedThisRequest[$entityType] = true;

        return $this->stamp($entityType) !== (self::$stamps[$entityType] ?? null);
    }

    /** Read an entity type's attributes, dropping whatever was held for it before. */
    private function load(string $entityType): void
    {
        $this->checkedThisRequest[$entityType] = true;

        self::$stamps[$entityType] = $this->stamp($entityType);

        self::$byEntityType[$entityType] = Eav::$attributeModel::query()
            ->forEntity($entityType)
            ->get()
            ->keyBy('id');

        unset(self::$unresolved[$entityType]);
    }

    /**
     * State of an entity type's rows, as far as a change to them is observable.
     */
    private function stamp(string $entityType): string
    {
        $state = Eav::$attributeModel::query()
            ->forEntity($entityType)
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as changed_at')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0, $state->changed_at ?? '']);
    }
}
