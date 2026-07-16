<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Eav;

class EnumRegistry
{
    /** @var array<int, Collection<int, AttributeEnum>> */
    private array $cache = [];

    /** Get all enums for a given attribute. */
    public function all(int $attributeId): Collection
    {
        return $this->load($attributeId);
    }

    /** Find an enum by ID. */
    public function find(int $attributeId, int $id): ?AttributeEnum
    {
        return $this->load($attributeId)->firstWhere('id', $id);
    }

    /** Find an enum by code. */
    public function findByCode(int $attributeId, string $code): ?AttributeEnum
    {
        return $this->load($attributeId)->firstWhere('code', $code);
    }

    /** Determine if the ID exists within the attribute enums. */
    public function isValidId(int $attributeId, int $id): bool
    {
        return $this->find($attributeId, $id) !== null;
    }

    /**
     * Coerce a filter value to a stored integer ID.
     * * Numeric values and null/empty pass through unchanged.
     * Non-numeric strings are resolved by code; unknown codes return null.
     */
    public function coerce(int $attributeId, mixed $value): mixed
    {
        if ($value === null || $value === '' || is_numeric($value)) {
            return $value;
        }

        return is_string($value) ? $this->findByCode($attributeId, $value)?->id : null;
    }

    /** Clear the cache for a specific attribute or everything. */
    public function forget(?int $attributeId = null): void
    {
        if ($attributeId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$attributeId]);
        }
    }

    /** Load enums from the database into the registry cache. */
    private function load(int $attributeId): Collection
    {
        return $this->cache[$attributeId] ??= Eav::$attributeEnumModel::query()
            ->where('attribute_id', $attributeId)
            ->with(['translations' => fn ($q) => $q->active()])
            ->get();
    }
}
