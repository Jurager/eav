<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\Concerns\FollowsSchemaVersion;

class EnumRegistry
{
    use FollowsSchemaVersion;

    /** @var array<int, Collection<int, AttributeEnum>> */
    private static array $byAttribute = [];

    /**
     * Get all enums for a given attribute.
     */
    public function all(int $attributeId): Collection
    {
        if ($this->schemaMoved()) {
            self::$byAttribute = [];
        }

        if (! isset(self::$byAttribute[$attributeId])) {
            $this->load($attributeId);
        }

        return self::$byAttribute[$attributeId];
    }

    /**
     * Find an enum by ID.
     */
    public function find(int $attributeId, int $id): ?AttributeEnum
    {
        return $this->all($attributeId)->firstWhere('id', $id);
    }

    /**
     * Find an enum by code.
     */
    public function findByCode(int $attributeId, string $code): ?AttributeEnum
    {
        return $this->all($attributeId)->firstWhere('code', $code);
    }

    /**
     * Determine if the ID exists within the attribute enums.
     */
    public function isValidId(int $attributeId, int $id): bool
    {
        return $this->find($attributeId, $id) !== null;
    }

    /**
     * Coerce a filter value to a stored integer ID, resolving non-numeric strings by code.
     */
    public function coerce(int $attributeId, mixed $value): mixed
    {
        if ($value === null || $value === '' || is_numeric($value)) {
            return $value;
        }

        return is_string($value) ? $this->findByCode($attributeId, $value)?->id : null;
    }

    /**
     * Invalidate cached options for an attribute or all attributes.
     */
    public function forget(?int $attributeId = null): void
    {
        if ($attributeId === null) {
            self::$byAttribute = [];
        } else {
            unset(self::$byAttribute[$attributeId]);
        }

        $this->schemaChanged();
    }

    /**
     * Clear cached data in this process.
     */
    public static function flush(): void
    {
        self::$byAttribute = [];
        static::forgetSchemaVersion();
    }

    /**
     * Load enums from the database into the registry cache, dropping whatever was held before.
     */
    private function load(int $attributeId): void
    {
        self::$byAttribute[$attributeId] = Eav::$attributeEnumModel::query()
            ->ordered()
            ->where('attribute_id', $attributeId)
            ->with('translations')
            ->get();
    }
}
