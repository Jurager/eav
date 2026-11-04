<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\FollowsSchemaVersion;

class AttributeGroupRegistry
{
    use CachesResolvedItems;
    use FollowsSchemaVersion;

    /** @var Collection<int, AttributeGroup>|null */
    private static ?Collection $groups = null;

    /**
     * Clones handed out by own(), one per group id, for the current request only.
     */
    private array $owned = [];

    /**
     * Get all cached attribute groups, keyed by ID.
     */
    public function all(): Collection
    {
        $this->dropStaleCaches();

        if (self::$groups === null) {
            $this->load();
        }

        return self::$groups;
    }

    /**
     * Determine if the registry has the given group.
     */
    public function has(int $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Get an attribute group by its ID.
     */
    public function get(int $id): ?AttributeGroup
    {
        $this->dropStaleCaches();

        if (self::$groups !== null && self::$groups->has($id)) {
            return self::$groups->get($id);
        }

        return $this->resolved('', $id, fn () => Eav::$attributeGroupModel::query()->find($id));
    }

    /**
     * Get a request-scoped group clone for locale-specific relations.
     */
    public function own(int $groupId): ?AttributeGroup
    {
        if (array_key_exists($groupId, $this->owned)) {
            return $this->owned[$groupId];
        }

        $group = $this->get($groupId);

        return $this->owned[$groupId] = $group !== null ? clone $group : null;
    }

    /**
     * Invalidate the cache across processes.
     */
    public function forget(): void
    {
        self::$groups = null;
        $this->forgetResolved();
        $this->owned = [];
        $this->schemaChanged();
    }

    /**
     * Clear cached data in this process.
     */
    public static function flush(): void
    {
        self::$groups = null;
        static::forgetSchemaVersion();
        static::flushResolved();
    }

    /**
     * Clear cached data when the schema changes.
     */
    private function dropStaleCaches(): void
    {
        if ($this->schemaMoved()) {
            self::$groups = null;
            $this->forgetResolved();
        }
    }

    /**
     * Reload cached records.
     */
    private function load(): void
    {
        self::$groups = Eav::$attributeGroupModel::query()->ordered()->get()->keyBy('id');
        $this->forgetResolved();
    }
}
