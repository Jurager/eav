<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupRegistry
{
    /** @var Collection<int, AttributeGroup>|null */
    private ?Collection $groups = null;

    /** Get all cached attribute groups, keyed by ID. */
    public function all(): Collection
    {
        return $this->groups ??= Eav::$attributeGroupModel::query()->get()->keyBy('id');
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
        $this->groups = null;
    }
}
