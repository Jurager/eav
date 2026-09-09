<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\TracksTableChanges;

class AttributeRegistry
{
    use CachesResolvedItems;
    use TracksTableChanges;

    /** @var array<string, Collection<int, Attribute>> */
    private static array $byEntityType = [];

    /** Get all cached attributes for a given entity type, keyed by ID. */
    public function all(string $entityType): Collection
    {
        if (! isset(self::$byEntityType[$entityType]) || $this->tableChanged(fn () => $this->stamp($entityType), $entityType)) {
            $this->load($entityType);
        }

        return self::$byEntityType[$entityType];
    }

    /** Determine if the registry has the given attribute for the given entity type. */
    public function has(string $entityType, int $id): bool
    {
        return $this->get($entityType, $id) !== null;
    }

    /** Get an attribute by its ID, scoped to the given entity type, without loading the whole entity type for a single lookup. */
    public function get(string $entityType, int $id): ?Attribute
    {
        $this->dropStaleCaches($entityType);

        if (isset(self::$byEntityType[$entityType]) && self::$byEntityType[$entityType]->has($id)) {
            return self::$byEntityType[$entityType]->get($id);
        }

        return $this->resolved($entityType, $id, fn () => Eav::$attributeModel::query()->forEntity($entityType)->find($id));
    }

    /** Clear the cache. */
    public function forget(?string $entityType = null): void
    {
        if ($entityType === null) {
            static::flush();
            $this->forgetTableChange();
            $this->forgetResolved();

            return;
        }

        unset(self::$byEntityType[$entityType]);
        $this->forgetTableChange($entityType);
        $this->forgetResolved($entityType);
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$byEntityType = [];
        static::flushTableChanges();
        static::flushResolved();
    }

    /** Drop the whole-set and point-lookup caches for an entity type if it moved since either was read. */
    private function dropStaleCaches(string $entityType): void
    {
        if ($this->tableChanged(fn () => $this->stamp($entityType), $entityType)) {
            unset(self::$byEntityType[$entityType]);
            $this->forgetResolved($entityType);
        }
    }

    /** Read an entity type's attributes, dropping whatever was held for it before. */
    private function load(string $entityType): void
    {
        $this->markTableFresh(fn () => $this->stamp($entityType), $entityType);

        self::$byEntityType[$entityType] = Eav::$attributeModel::query()
            ->forEntity($entityType)
            ->get()
            ->keyBy('id');

        $this->forgetResolved($entityType);
    }

    /** Get the state of an entity type's rows, as far as a change is observable. */
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
