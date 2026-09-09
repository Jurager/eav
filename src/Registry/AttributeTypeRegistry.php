<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\TracksTableChanges;

class AttributeTypeRegistry
{
    use CachesResolvedItems;
    use TracksTableChanges;

    /** @var Collection<string, AttributeType>|null */
    private static ?Collection $types = null;

    /** @var Collection<int, AttributeType>|null */
    private static ?Collection $typesById = null;

    /** Get all cached attribute types. */
    public function all(): Collection
    {
        if (self::$types === null || $this->tableChanged(fn () => $this->stamp())) {
            $this->load();
        }

        return self::$types;
    }

    /** Get all registered attribute type codes. */
    public function codes(): array
    {
        return $this->all()->keys()->toArray();
    }

    /** Determine if the registry has the given type. */
    public function has(string $code): bool
    {
        return $this->find($code) !== null;
    }

    /** Find an attribute type by its code, without loading the whole table for a single lookup. */
    public function find(string $code): ?AttributeType
    {
        $this->dropStaleCaches();

        if (self::$types !== null && self::$types->has($code)) {
            return self::$types->get($code);
        }

        return $this->resolved('code', $code, fn () => Eav::$attributeTypeModel::query()->where('code', $code)->first());
    }

    /** Get an attribute type by its ID, without loading the whole table for a single lookup. */
    public function get(int $id): ?AttributeType
    {
        $this->dropStaleCaches();

        if (self::$typesById !== null && self::$typesById->has($id)) {
            return self::$typesById->get($id);
        }

        return $this->resolved('id', $id, fn () => Eav::$attributeTypeModel::query()->find($id));
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        self::$types = null;
        self::$typesById = null;
        $this->forgetTableChange();
        $this->forgetResolved();
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$types = null;
        self::$typesById = null;
        static::flushTableChanges();
        static::flushResolved();
    }

    /** Drop the whole-table and point-lookup caches if the table moved since either was read. */
    private function dropStaleCaches(): void
    {
        if ($this->tableChanged(fn () => $this->stamp())) {
            self::$types = null;
            self::$typesById = null;
            $this->forgetResolved();
        }
    }

    /** Read the table, dropping whatever was held before. */
    private function load(): void
    {
        $this->markTableFresh(fn () => $this->stamp());
        self::$types = Eav::$attributeTypeModel::query()->get()->keyBy('code');
        self::$typesById = self::$types->values()->keyBy('id');
        $this->forgetResolved();
    }

    /** Get the state of the table, as far as a change is observable. */
    private function stamp(): string
    {
        $state = Eav::$attributeTypeModel::query()
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as changed_at')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0, $state->changed_at ?? '']);
    }
}
