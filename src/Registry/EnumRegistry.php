<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\Concerns\TracksTableChanges;

class EnumRegistry
{
    use TracksTableChanges;

    /** @var array<int, Collection<int, AttributeEnum>> */
    private static array $byAttribute = [];

    /** Get all enums for a given attribute. */
    public function all(int $attributeId): Collection
    {
        if (! isset(self::$byAttribute[$attributeId]) || $this->tableChanged(fn () => $this->stamp($attributeId), (string) $attributeId)) {
            $this->load($attributeId);
        }

        return self::$byAttribute[$attributeId];
    }

    /** Find an enum by ID. */
    public function find(int $attributeId, int $id): ?AttributeEnum
    {
        return $this->all($attributeId)->firstWhere('id', $id);
    }

    /** Find an enum by code. */
    public function findByCode(int $attributeId, string $code): ?AttributeEnum
    {
        return $this->all($attributeId)->firstWhere('code', $code);
    }

    /** Determine if the ID exists within the attribute enums. */
    public function isValidId(int $attributeId, int $id): bool
    {
        return $this->find($attributeId, $id) !== null;
    }

    /** Coerce a filter value to a stored integer ID, resolving non-numeric strings by code. */
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
            static::flush();
            $this->forgetTableChange();

            return;
        }

        unset(self::$byAttribute[$attributeId]);
        $this->forgetTableChange((string) $attributeId);
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$byAttribute = [];
        static::flushTableChanges();
    }

    /** Load enums from the database into the registry cache, dropping whatever was held before. */
    private function load(int $attributeId): void
    {
        $this->markTableFresh(fn () => $this->stamp($attributeId), (string) $attributeId);

        self::$byAttribute[$attributeId] = Eav::$attributeEnumModel::query()
            ->where('attribute_id', $attributeId)
            ->with('translations')
            ->get();
    }

    /** Get the state of an attribute's enums, as far as a change is observable. */
    private function stamp(int $attributeId): string
    {
        $state = Eav::$attributeEnumModel::query()
            ->where('attribute_id', $attributeId)
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as changed_at')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0, $state->changed_at ?? '']);
    }
}
