<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\AttributeType;

class AttributeTypeRegistry
{
    /** @var Collection<string, AttributeType>|null */
    private static ?Collection $types = null;

    /** @var Collection<int, AttributeType>|null */
    private static ?Collection $typesById = null;

    private static ?string $stamp = null;

    private bool $checked = false;

    /** Get all cached attribute types. */
    public function all(): Collection
    {
        if (self::$types === null || $this->changed()) {
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
        return $this->all()->has($code);
    }

    /** Find an attribute type by its code. */
    public function find(string $code): ?AttributeType
    {
        return $this->all()->get($code);
    }

    /** Get an attribute type by its ID. */
    public function get(int $id): ?AttributeType
    {
        $this->all();

        return self::$typesById?->get($id);
    }

    /** Clear the internal cache. */
    public function forget(): void
    {
        self::$types = null;
        self::$typesById = null;
        self::$stamp = null;
        $this->checked = false;
    }

    /** Drop everything the process holds. */
    public static function flush(): void
    {
        self::$types = null;
        self::$typesById = null;
        self::$stamp = null;
    }

    /** Determine if the table changed since it was last read. Checked at most once per request. */
    private function changed(): bool
    {
        if ($this->checked) {
            return false;
        }

        $this->checked = true;

        return $this->stamp() !== self::$stamp;
    }

    /** Read the table, dropping whatever was held before. */
    private function load(): void
    {
        $this->checked = true;
        self::$stamp = $this->stamp();
        self::$types = Eav::$attributeTypeModel::query()->get()->keyBy('code');
        self::$typesById = self::$types->values()->keyBy('id');
    }

    /** Get the state of the table, as far as a change is observable without an updated_at column. */
    private function stamp(): string
    {
        $state = Eav::$attributeTypeModel::query()
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as total, max(id) as last_id')
            ->first();

        return implode(':', [$state->total ?? 0, $state->last_id ?? 0]);
    }
}
