<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\SchemaRegistry;

/**
 * The attribute definitions available to an entity or entity type.
 */
class EntitySchema
{
    /** @var Collection<int, Attribute>|null */
    private ?Collection $definitions = null;

    private int $revision = -1;

    /**
     * @param  Attributable|null  $scope  Entity whose scope narrows the schema; null for every attribute of the type.
     */
    public function __construct(
        private readonly AttributeRegistry $registry,
        private readonly SchemaRegistry $schemas,
        private readonly FieldFactory $fields,
        private readonly string $entityType,
        private readonly ?Attributable $scope = null,
    ) {}

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function revision(): int
    {
        if ($this->revision !== $this->schemas->revision()) {
            $this->definitions = null;
            $this->revision = $this->schemas->revision();
        }

        return $this->revision;
    }

    /**
     * Get all definitions in schema order.
     *
     * @return Collection<int, Attribute>
     */
    public function attributes(): Collection
    {
        $this->revision();

        return $this->definitions ??= $this->load()->loadMissing('type');
    }

    /**
     * Get the requested definitions in schema order.
     *
     * @param  list<string>  $codes
     * @return Collection<int, Attribute>
     */
    public function only(array $codes): Collection
    {
        $this->revision();

        if ($codes === []) {
            return collect();
        }

        if ($this->definitions !== null) {
            return $this->definitions->whereIn('code', $codes)->values();
        }

        $attributes = $this->registry->forCodes($this->entityType, array_map(strval(...), $codes));

        if ($attributes->isNotEmpty() && ($available = $this->idsInScope()) !== null) {
            $available = array_flip($available);
            $attributes = $attributes->filter(static fn (Attribute $attribute): bool => isset($available[$attribute->getKey()]));
        }

        return $attributes->sortBy(static fn (Attribute $attribute): array => $attribute->schemaPosition())->values();
    }

    /**
     * Keep fields allowed by the current schema.
     *
     * @param  array<string, Field>  $fields
     * @return array<string, Field>
     */
    public function filterFields(array $fields): array
    {
        $available = $this->only(array_keys($fields))->keyBy('id');

        return array_filter($fields, static fn (Field $field): bool => $available->has($field->attribute()->getKey()));
    }

    /**
     * Create blank fields keyed by attribute code.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->attributes()
            ->mapWithKeys(fn (Attribute $attribute): array => [$attribute->code => $this->make($attribute, $this->scope)])
            ->all();
    }

    /**
     * Fill valid fields, skipping unknown codes and reporting rejected attributes.
     *
     * @param  array<string, mixed>  $data
     * @param  (callable(string): void)|null  $onRejected
     * @return Collection<string, Field>
     */
    public function fill(array $data, ?Attributable $entity = null, ?callable $onRejected = null): Collection
    {
        $entity ??= $this->scope;
        $side = $entity !== null ? HeldBy::of($entity->isVariant()) : null;
        $definitions = $this->attributes()->keyBy('code');
        $filled = collect();

        foreach ($data as $code => $value) {
            $attribute = $definitions->get($code);

            if ($attribute === null) {
                continue;
            }

            if ($side !== null && ! $attribute->isHeldBy($side)) {
                $onRejected !== null && $onRejected((string) $code);

                continue;
            }

            $field = $this->make($attribute, $entity);

            if ($field->fill($value)) {
                $filled->put($code, $field);
            }
        }

        return $filled;
    }

    /**
     * Create a field bound to the entity.
     */
    public function make(Attribute $attribute, ?Attributable $entity = null): Field
    {
        return $this->fields->make($attribute)->forEntity($entity ?? $this->scope);
    }

    /** @return Collection<int, Attribute> */
    private function load(): Collection
    {
        if ($this->scope === null) {
            return $this->schemas->resolve(
                "{$this->entityType}:all",
                fn () => Eav::$attributeModel::query()->forEntity($this->entityType)->ordered()->get(),
            );
        }

        $scope = $this->scope->attributeScopeIds();
        sort($scope);

        return $this->schemas->resolve(
            "{$this->entityType}:scope:".($scope === [] ? 'none' : md5(json_encode($scope, JSON_THROW_ON_ERROR))),
            function (): Collection {
                $ids = $this->idsInScope();

                if ($ids === []) {
                    return new EloquentCollection;
                }

                return Eav::$attributeModel::query()
                    ->forEntity($this->entityType)
                    ->when($ids !== null, fn ($query) => $query->whereKey($ids))
                    ->ordered()
                    ->get();
            },
        );
    }

    /**
     * Get the allowed attribute IDs, or null for an unrestricted scope.
     *
     * @return list<int|string>|null
     */
    private function idsInScope(): ?array
    {
        if ($this->scope === null) {
            return null;
        }

        $scope = $this->scope->attributeScopeIds();
        sort($scope);

        return $this->schemas->resolve(
            "{$this->entityType}:ids:".md5(json_encode($scope, JSON_THROW_ON_ERROR)),
            fn (): Collection => collect([$this->scope->attributeIdsInScope()]),
        )->first();
    }
}
