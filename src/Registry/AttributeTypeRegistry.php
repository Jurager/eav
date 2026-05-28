<?php

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

/**
 * In-memory cache of attribute types keyed by code.
 */
class AttributeTypeRegistry
{
    /** @var Collection<string, AttributeType>|null */
    private ?Collection $types = null;

    /** @return Collection<string, AttributeType> */
    public function all(): Collection
    {
        return $this->types ??= EavModels::query('attribute_type')->get()->keyBy('code');
    }

    /** @return array<string> */
    public function codes(): array
    {
        return $this->all()->keys()->all();
    }

    public function has(string $code): bool
    {
        return $this->all()->has($code);
    }

    public function find(string $code): ?AttributeType
    {
        return $this->all()->get($code);
    }

    public function forget(): void
    {
        $this->types = null;
    }
}
