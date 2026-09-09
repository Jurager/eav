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

    /**
     * One clone per group, for the current request only — unlike the row data above, a clone is
     * safe to carry locale-scoped relations (translations) that the shared static row above must
     * never hold. Handing every Attribute the SAME clone (instead of a fresh one each time) means
     * a lazy ->translations access on it queries once per group per request, however many
     * Attributes reference that group — not once per Attribute (see {@see forRequest()}).
     *
     * This stays a plain instance property: the registry itself is resolved fresh each request
     * ({@see \Jurager\Eav\EavServiceProvider} binds it `scoped`), which is exactly the lifetime
     * this cache is safe for.
     *
     * @var array<int, AttributeGroup>
     */
    private array $hydrated = [];

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

    /**
     * Get a group to attach relations to, safe for the current request only.
     *
     * The row itself is safe to share across every request in the worker — it carries no
     * locale — but a relation like `translations` resolves against whichever locale the
     * current request negotiated, so it can never be cached on that shared row. This hands
     * out a clone instead, one per group id, reused for the rest of the request: the first
     * `->translations` access on it (however that happens — resource serialization, direct
     * access, anywhere) queries once, and every other Attribute that shares this group in
     * this request reuses that same loaded relation rather than re-querying (see {@see
     * \Jurager\Eav\Models\Attribute::hydrateFromRegistries()}).
     */
    public function forRequest(int $groupId): ?AttributeGroup
    {
        if (array_key_exists($groupId, $this->hydrated)) {
            return $this->hydrated[$groupId];
        }

        $group = $this->get($groupId);

        return $this->hydrated[$groupId] = $group !== null ? clone $group : null;
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        self::$groups = null;
        self::$stamp = null;
        $this->checkedThisRequest = false;
        $this->hydrated = [];
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
