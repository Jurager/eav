<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use Jurager\Eav\Registry\Concerns\FollowsSchemaVersion;

class AttributeRegistry
{
    use CachesResolvedItems;
    use FollowsSchemaVersion;

    /** @var array<string, Collection<int, Attribute>> */
    private static array $byEntityType = [];

    /** @var array<string, Collection<string, Attribute|null>> */
    private static array $byCode = [];

    /** @var array<string, array<string, Collection<int, Attribute>>> */
    private static array $byTypes = [];

    /**
     * Resolve the definitions of the given field types without reading the rest of the schema.
     *
     * @param  list<string>  $types
     * @return Collection<int, Attribute>
     */
    public function forTypes(string $entityType, array $types): Collection
    {
        if ($types === []) {
            return collect();
        }

        $this->dropStaleCaches($entityType);

        if (isset(self::$byEntityType[$entityType])) {
            return self::$byEntityType[$entityType]
                ->filter(static fn (Attribute $attribute): bool => in_array($attribute->type?->code, $types, true));
        }

        sort($types);

        return self::$byTypes[$entityType][implode(',', $types)] ??= $this->remember(
            $entityType,
            Eav::$attributeModel::query()
                ->forEntity($entityType)
                ->ordered()
                ->whereHas('type', static fn ($query) => $query->whereIn('code', $types))
                ->get()
                ->keyBy('id'),
        );
    }

    /**
     * Get all cached attributes for a given entity type, keyed by ID.
     */
    public function all(string $entityType): Collection
    {
        $this->dropStaleCaches($entityType);

        if (! isset(self::$byEntityType[$entityType])) {
            $this->load($entityType);
        }

        return self::$byEntityType[$entityType];
    }

    /**
     * Determine if the registry has the given attribute for the given entity type.
     */
    public function has(string $entityType, int $id): bool
    {
        return $this->get($entityType, $id) !== null;
    }

    /**
     * Resolve only the requested definitions, remembering missing codes as well as matches.
     *
     * @param  list<string>  $codes
     * @return Collection<int, Attribute>
     */
    public function forCodes(string $entityType, array $codes): Collection
    {
        if ($codes === []) {
            return collect();
        }

        $this->dropStaleCaches($entityType);
        $cached = self::$byCode[$entityType] ??= collect();
        $missing = array_values(array_filter(array_unique($codes), fn (string $code): bool => ! $cached->has($code)));

        if ($missing !== []) {
            $attributes = isset(self::$byEntityType[$entityType])
                ? collect()
                : Eav::$attributeModel::query()->forEntity($entityType)->whereIn('code', $missing)->get();

            foreach ($missing as $code) {
                $cached->put($code, null);
            }

            $this->remember($entityType, $attributes);
        }

        return collect($codes)->unique()->map(fn (string $code) => $cached->get($code))->filter()->values();
    }

    /**
     * Get an attribute by ID for the given entity type.
     */
    public function get(string $entityType, int $id): ?Attribute
    {
        $this->dropStaleCaches($entityType);

        if (isset(self::$byEntityType[$entityType]) && self::$byEntityType[$entityType]->has($id)) {
            return self::$byEntityType[$entityType]->get($id);
        }

        return $this->resolved($entityType, $id, fn () => Eav::$attributeModel::query()->forEntity($entityType)->find($id));
    }

    /**
     * Reuse a known attribute without querying it; freshness is checked once per request.
     */
    public function cached(string $entityType, int $id): ?Attribute
    {
        $this->dropStaleCaches($entityType);

        return (self::$byEntityType[$entityType] ?? null)?->get($id) ?? self::$resolvedItems[$entityType][$id] ?? null;
    }

    /**
     * Invalidate cached attributes for one or all entity types.
     */
    public function forget(?string $entityType = null): void
    {
        if ($entityType === null) {
            self::$byEntityType = self::$byCode = self::$byTypes = [];
            $this->forgetResolved();
        } else {
            unset(self::$byEntityType[$entityType], self::$byCode[$entityType], self::$byTypes[$entityType]);
            $this->forgetResolved($entityType);
        }

        $this->schemaChanged();
    }

    /**
     * Clear cached data in this process.
     */
    public static function flush(): void
    {
        self::$byEntityType = [];
        self::$byCode = [];
        self::$byTypes = [];
        static::forgetSchemaVersion();
        static::flushResolved();
    }

    /**
     * Clear cached data when the schema changes.
     */
    private function dropStaleCaches(string $entityType): void
    {
        if ($this->schemaMoved()) {
            self::$byEntityType = self::$byCode = self::$byTypes = [];
            $this->forgetResolved();
        }
    }

    /**
     * @param  Collection<int, Attribute>  $attributes
     * @return Collection<int, Attribute>
     */
    private function remember(string $entityType, Collection $attributes): Collection
    {
        $codes = self::$byCode[$entityType] ??= collect();

        foreach ($attributes as $attribute) {
            $codes->put($attribute->code, $attribute);
            self::$resolvedItems[$entityType][$attribute->getKey()] = $attribute;
        }

        return $attributes;
    }

    /**
     * Read an entity type's attributes, dropping whatever was held for it before.
     */
    private function load(string $entityType): void
    {
        self::$byEntityType[$entityType] = Eav::$attributeModel::query()
            ->forEntity($entityType)
            ->ordered()
            ->get()
            ->keyBy('id');

        self::$byCode[$entityType] = self::$byEntityType[$entityType]->keyBy('code');

        $this->forgetResolved($entityType);
    }
}
