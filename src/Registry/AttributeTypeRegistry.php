<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeType;

class AttributeTypeRegistry
{
    /** @var Collection<string, AttributeType>|null */
    private ?Collection $types = null;

    /** Get all cached attribute types. */
    public function all(): Collection
    {
        return $this->types ??= Eav::$attributeTypeModel::query()->get()->keyBy('code');
    }

    /** Get all registered attribute type codes. */
    public function codes(): array
    {
        return $this->all()->keys()->toArray();
    }

    /** Determine if the registry has the given type. */
    public function has(string $code): bool
    {
        return $this->all()->has($code);
    }

    /** Find an attribute type by its code. */
    public function find(string $code): ?AttributeType
    {
        return $this->all()->get($code);
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        $this->types = null;
    }
}
