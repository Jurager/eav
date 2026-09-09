<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupRegistry
{
    /** @var Collection<int, AttributeGroup>|null */
    private static ?Collection $groups = null;

    private static ?string $stamp = null;

    private bool $checkedThisRequest = false;

    /** Get all cached attribute groups, keyed by ID. */
    public function all(): Collection
    {
        if (self::$groups === null || $this->changed()) {
            $this->load();
        }

        return self::$groups;
    }

    /** Determine if the registry has the given group. */
    public function has(int $id): bool
    {
        return $this->all()->has($id);
    }

    /** Get an attribute group by its ID. */
    public function get(int $id): ?AttributeGroup
    {
        return $this->all()->get($id);
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        self::$groups = null;
        self::$stamp = null;
        $this->checkedThisRequest = false;
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$groups = null;
        self::$stamp = null;
    }

    /** Determine if the table changed since it was last read. Checked at most once per request. */
    private function changed(): bool
    {
        if ($this->checkedThisRequest) {
            return false;
        }

        $this->checkedThisRequest = true;

        return $this->stamp() !== self::$stamp;
    }

    /** Read the table, dropping whatever was held before. */
    private function load(): void
    {
        $this->checkedThisRequest = true;
        self::$stamp = $this->stamp();
        self::$groups = Eav::$attributeGroupModel::query()->get()->keyBy('id');
    }

    /** Get the state of the table, as far as a change is observable without an updated_at column. */
    private function stamp(): string
    {
        $state = Eav::$attributeGroupModel::query()
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0]);
    }
}
