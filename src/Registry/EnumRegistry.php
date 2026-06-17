<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Support\EavModels;

/**
 * In-memory cache of AttributeEnum models keyed by attribute ID.
 */
class EnumRegistry
{
    /** @var array<int, Collection<int, AttributeEnum>> */
    private array $cache = [];

    /** @return Collection<int, AttributeEnum> */
    public function all(int $attributeId): Collection
    {
        return $this->load($attributeId);
    }

    public function find(int $attributeId, int $id): ?AttributeEnum
    {
        return $this->load($attributeId)->firstWhere('id', $id);
    }

    public function findByCode(int $attributeId, string $code): ?AttributeEnum
    {
        return $this->load($attributeId)->firstWhere('code', $code);
    }

    public function isValidId(int $attributeId, int $id): bool
    {
        return $this->find($attributeId, $id) !== null;
    }

    /**
     * Coerce a filter value to a stored integer ID.
     *
     * Numeric values and null/empty pass through unchanged.
     * Non-numeric strings are resolved by code; unknown codes return null.
     */
    public function coerce(int $attributeId, mixed $value): mixed
    {
        if ($value === null || $value === '' || is_numeric($value)) {
            return $value;
        }

        return is_string($value) ? $this->findByCode($attributeId, $value)?->id : null;
    }

    public function forget(?int $attributeId = null): void
    {
        if ($attributeId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$attributeId]);
        }
    }

    /** @return Collection<int, AttributeEnum> */
    private function load(int $attributeId): Collection
    {
        return $this->cache[$attributeId] ??= EavModels::query('attribute_enum')
            ->where('attribute_id', $attributeId)
            ->with(['translations' => fn ($q) => $q->active()])
            ->get();
    }
}
