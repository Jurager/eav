<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\TracksTableChanges;

class AttributeGroupRegistry
{
    use CachesResolvedItems;
    use TracksTableChanges;

    /** @var Collection<int, AttributeGroup>|null */
    private static ?Collection $groups = null;

    /** Clones handed out by own(), one per group id, for the current request only. */
    private array $owned = [];

    /** Get all cached attribute groups, keyed by ID. */
    public function all(): Collection
    {
        if (self::$groups === null || $this->tableChanged(fn () => $this->stamp())) {
            $this->load();
        }

        return self::$groups;
    }

    /** Determine if the registry has the given group. */
    public function has(int $id): bool
    {
        return $this->get($id) !== null;
    }

    /** Get an attribute group by its ID, without loading the whole table for a single lookup. */
    public function get(int $id): ?AttributeGroup
    {
        $this->dropStaleCaches();

        if (self::$groups !== null && self::$groups->has($id)) {
            return self::$groups->get($id);
        }

        return $this->resolved('', $id, fn () => Eav::$attributeGroupModel::query()->find($id));
    }

    /** Get a group to attach locale-scoped relations to — a clone, safe for the current request only, shared by every Attribute referencing that group id. */
    public function own(int $groupId): ?AttributeGroup
    {
        if (array_key_exists($groupId, $this->owned)) {
            return $this->owned[$groupId];
        }

        $group = $this->get($groupId);

        return $this->owned[$groupId] = $group !== null ? clone $group : null;
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        self::$groups = null;
        $this->forgetTableChange();
        $this->forgetResolved();
        $this->owned = [];
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$groups = null;
        static::flushTableChanges();
        static::flushResolved();
    }

    /** Drop the whole-table and point-lookup caches if the table moved since either was read. */
    private function dropStaleCaches(): void
    {
        if ($this->tableChanged(fn () => $this->stamp())) {
            self::$groups = null;
            $this->forgetResolved();
        }
    }

    /** Read the table, dropping whatever was held before. */
    private function load(): void
    {
        $this->markTableFresh(fn () => $this->stamp());
        self::$groups = Eav::$attributeGroupModel::query()->get()->keyBy('id');
        $this->forgetResolved();
    }

    /** Get the state of the table, as far as a change is observable. */
    private function stamp(): string
    {
        $state = Eav::$attributeGroupModel::query()
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as changed_at')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0, $state->changed_at ?? '']);
    }
}
