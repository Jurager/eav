<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Attribute;

class AttributeRegistry
{
    /** @var Collection<int, Attribute>|null */
    private ?Collection $attributes = null;

    /** Get all cached attributes, keyed by ID. */
    public function all(): Collection
    {
        return $this->attributes ??= Eav::$attributeModel::query()->get()->keyBy('id');
    }

    /** Determine if the registry has the given attribute. */
    public function has(int $id): bool
    {
        return $this->all()->has($id);
    }

    /** Get an attribute by its ID. */
    public function get(int $id): ?Attribute
    {
        return $this->all()->get($id);
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        $this->attributes = null;
    }
}
