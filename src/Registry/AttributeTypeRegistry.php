<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\FollowsSchemaVersion;

class AttributeTypeRegistry
{
    use CachesResolvedItems;
    use FollowsSchemaVersion;

    /** @var Collection<string, AttributeType>|null */
    private static ?Collection $types = null;

    /** @var Collection<int, AttributeType>|null */
    private static ?Collection $typesById = null;

    /**
     * Get all cached attribute types.
     */
    public function all(): Collection
    {
        $this->dropStaleCaches();

        if (self::$types === null) {
            $this->load();
        }

        return self::$types;
    }

    /**
     * Get all registered attribute type codes.
     */
    public function codes(): array
    {
        return $this->all()->keys()->toArray();
    }

    /**
     * Determine if the registry has the given type.
     */
    public function has(string $code): bool
    {
        return $this->find($code) !== null;
    }

    /**
     * Find an attribute type by its code.
     */
    public function find(string $code): ?AttributeType
    {
        $this->dropStaleCaches();

        if (self::$types !== null && self::$types->has($code)) {
            return self::$types->get($code);
        }

        return $this->resolved('code', $code, fn () => Eav::$attributeTypeModel::query()->where('code', $code)->first());
    }

    /**
     * Get an attribute type by its ID.
     */
    public function get(int $id): ?AttributeType
    {
        $this->dropStaleCaches();

        if (self::$typesById !== null && self::$typesById->has($id)) {
            return self::$typesById->get($id);
        }

        return $this->resolved('id', $id, fn () => Eav::$attributeTypeModel::query()->find($id));
    }

    /**
     * Invalidate the cache across processes.
     */
    public function forget(): void
    {
        self::$types = null;
        self::$typesById = null;
        $this->forgetResolved();
        $this->schemaChanged();
    }

    /**
     * Clear cached data in this process.
     */
    public static function flush(): void
    {
        self::$types = null;
        self::$typesById = null;
        static::forgetSchemaVersion();
        static::flushResolved();
    }

    /**
     * Clear cached data when the schema changes.
     */
    private function dropStaleCaches(): void
    {
        if ($this->schemaMoved()) {
            self::$types = null;
            self::$typesById = null;
            $this->forgetResolved();
        }
    }

    /**
     * Reload cached records.
     */
    private function load(): void
    {
        self::$types = Eav::$attributeTypeModel::query()->get()->keyBy('code');
        self::$typesById = self::$types->values()->keyBy('id');
        $this->forgetResolved();
    }
}
