<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

/**
 * In-memory cache for attribute type data.
 */
class AttributeTypeRegistry
{
    /** @var Collection<string, AttributeType>|null  code → model */
    private ?Collection $types = null;

    /**
     * All attribute types keyed by code.
     *
     * @return Collection<string, AttributeType>
     */
    public function all(): Collection
    {
        return $this->types ??= EavModels::query('attribute_type')->get()->keyBy('code');
    }

    /**
     * All type codes.
     *
     * @return array<string>
     */
    public function codes(): array
    {
        return $this->all()->keys()->all();
    }

    public function has(string $code): bool
    {
        return $this->all()->has($code);
    }

    /**
     * Find an attribute type by code, or null if not found.
     */
    public function find(string $code): ?AttributeType
    {
        return $this->all()->get($code);
    }

    /**
     * Forget all cached data.
     */
    public function forget(): void
    {
        $this->types = null;
    }
}
