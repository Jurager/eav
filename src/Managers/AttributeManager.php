<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\HeldBy;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\MissingEntityException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Scopes\ActiveLocaleScope;
use Jurager\Eav\Support\AttributePersister;
use Jurager\Eav\Support\AttributeQueryBuilder;
use Jurager\Eav\Support\BatchAttributePersister;
use Throwable;

class AttributeManager
{
    /** @var array<string, Field> */
    protected array $fields = [];

    private bool $schemaLoaded = false;

    private ?AttributePersister $persister = null;

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

    private ?AttributeQueryBuilder $builder = null;

    /** FQCN stored for schema-only managers created from a class string. */
    protected ?string $entityClass = null;

    public function __construct(
        protected ?Attributable $entity = null,
        private readonly ?FieldFactory $fieldFactory = null,
        private readonly ?EnumRegistry $enumRegistry = null,
        private readonly ?SchemaRegistry $schemaRegistry = null,
    ) {
    }

    /** Create a manager for an entity instance, FQCN, or morph-map key. */
    public static function for(string|Attributable $entity): static
    {
        if ($entity instanceof Attributable) {
            return app(static::class, ['entity' => $entity]);
        }

        $registry = app(SchemaRegistry::class);

        if (class_exists($entity)) {
            if (! is_subclass_of($entity, Attributable::class)) {
                throw InvalidConfigurationException::missingAttributableContract($entity);
            }

            $instance = new $entity();
            $manager = static::buildFromAttributable($instance, $registry);
            $manager->entityClass = $entity;

            return $manager;
        }

        $attributes = $registry->resolve(
            "{$entity}:default",
            fn () => Eav::$attributeModel::query()->forEntity($entity)->withRelations()->get(),
        );

        return static::buildFromCollection($attributes);
    }

    /** Return a schema-only manager for an entity or a preloaded attribute collection.
     * @throws JsonException
     */
    public static function schema(Attributable|Collection $entityOrAttributes): static
    {
        return $entityOrAttributes instanceof Collection
            ? static::buildFromCollection($entityOrAttributes)
            : static::buildFromAttributable($entityOrAttributes, app(SchemaRegistry::class));
    }

    /**
     * Persist attribute values for multiple entities in chunked batches.
     *
     * @param Collection<int, array{entity: Attributable, data: array<string, mixed>}> $batch
     * @param callable(Throwable, Attributable): void|null $onError Called when persisting an entity fails.
     * @param callable(Attributable, string): void|null $onRejected Called for each attribute code silently
     *  dropped because the entity's side (parent/variant) is not allowed to hold it (held_by mismatch).
     */
    public static function sync(Collection $batch, ?self $prebuiltSchema = null, int $chunkSize = 500, ?callable $onError = null, ?callable $onRejected = null): void
    {
        if ($batch->isEmpty()) {
            return;
        }

        foreach ($batch->chunk(max(1, $chunkSize)) as $chunk) {
            $persister = app(BatchAttributePersister::class);

            foreach ($chunk as $item) {
                $entity = $item['entity'];

                // Unfilled fields validate fine (an empty value is a valid one) but must not reach the persister.
                // Blank row still counts as the entity's own value and blocks a variant from inheriting the parent's.
                $fields = ($prebuiltSchema ?? static::schema($entity))->fill(
                    $item['data'],
                    $entity,
                    $onRejected !== null ? fn (string $code) => $onRejected($entity, $code) : null,
                )->filter->isFilled();

                if ($fields->isNotEmpty()) {
                    $persister->add($entity, $fields);
                }
            }

            $persister->flush($onError);
        }
    }

    /** Ensure the schema is loaded, hydrated with the entity's stored (and inherited) values. */
    public function ensureSchema(): static
    {
        if ($this->schemaLoaded) {
            return $this;
        }

        $attributes = ($this->query($this->entity?->attributeScopeIds() ?? [])?->get() ?? collect())
            ->reject(fn ($attr) => isset($this->fields[$attr->code]));

        if ($attributes->isNotEmpty()) {
            $this->hydrate($attributes);
        }

        $this->schemaLoaded = true;

        return $this;
    }

    /** Batch-load and hydrate specific fields by code. */
    public function ensureFields(array $codes): void
    {
        $codes = array_diff($codes, array_keys($this->fields));

        if (empty($codes) || $this->schemaLoaded) {
            return;
        }

        $attributes = $this->attributesFor($codes);

        if ($attributes->isNotEmpty()) {
            $this->hydrate($attributes);
        }
    }

    /**
     * Attributes for the given codes, resolved once per entity type and scope.
     *
     * @param list<string> $codes
     */
    private function attributesFor(array $codes): Collection
    {
        $scope = $this->entity?->attributeScopeIds() ?? [];
        sort($scope);

        $key = $this->resolveEntity()->getEntityType() . ':schema:' . implode(',', $scope);

        return $this->schemaRegistry
            ->resolve($key, fn (): Collection => $this->query($scope)?->get() ?? collect())
            ->whereIn('code', $codes)
            ->values();
    }

    /** Return all loaded Field objects. */
    public function fields(): array
    {
        return $this->fields;
    }

    /** Return a hydrated Field by code. */
    public function field(string $code): ?Field
    {
        if (! isset($this->fields[$code])) {
            $this->ensureFields([$code]);
        }

        return $this->fields[$code] ?? null;
    }

    /** Get value for a field. */
    public function value(string $code, ?int $localeId = null): mixed
    {
        return $this->field($code)?->value($localeId);
    }

    /** Set value in memory. */
    public function set(string $code, mixed $value, ?int $localeId = null): static
    {
        $this->field($code)?->set($value, $localeId);

        return $this;
    }

    /** Append a value (or array of values) to a multi-value field in memory, keeping existing values. */
    public function add(string $code, mixed $value, ?int $localeId = null): static
    {
        $this->field($code)?->add($value, $localeId);

        return $this;
    }

    /** Persist a single attribute value. */
    public function save(string $code): void
    {
        $field = $this->field($code);

        if ($field?->isFilled()) {
            $this->persister()->save($field);
        }
    }

    /** Persist the given fields. */
    public function attach(array $fields): void
    {
        foreach ($fields as $code => $field) {
            $this->fields[$code] = $field;
        }

        $this->persister()->persist(collect($fields)->filter->isFilled());
    }

    /** Replace all entity_attribute rows with the given fields. */
    public function replace(array $fields): void
    {
        $this->fields = $fields;
        $this->persister()->replace(collect($this->fields)->filter->isFilled());
    }

    /** Delete entity_attribute rows for the given attribute IDs. */
    public function detach(array $ids): void
    {
        $this->persister()->detach($ids);
    }

    /**
     * Fill fields from raw data, restricted to the attributes the entity's side may hold.
     */
    public function fill(array $data, ?Attributable $entity = null, ?callable $onRejected = null): Collection
    {
        $this->ensureSchema();

        $resolvedEntity = $entity ?? $this->entity;
        $side = $resolvedEntity !== null ? HeldBy::of($resolvedEntity->isVariant()) : null;

        return collect($data)
            ->filter(function ($_, $code) use ($side, $onRejected) {
                if (! isset($this->fields[$code])) {
                    return false;
                }

                if ($side !== null && ! $this->fields[$code]->attribute()->isHeldBy($side)) {
                    $onRejected?->__invoke($code);

                    return false;
                }

                return true;
            })
            ->map(function ($value, $code) {
                $field = clone $this->fields[$code];

                return $field->fill($value) ? $field : null;
            })
            ->filter();
    }

    /** Return entity_attribute records with a resolved typed value. */
    public function values(?array $codes = null, ?int $paginated = null): Collection|LengthAwarePaginator
    {
        $transform = fn (Model $model): Model => tap($model, function ($m) {
            $m->value = $this->makeField($m->attribute)->read($m);
        });

        if (($collection = $this->loadedValues()) !== null) {
            if ($codes !== null) {
                $collection = $collection->filter(
                    fn ($ea) => $ea->relationLoaded('attribute') && in_array($ea->attribute?->getAttribute('code'), $codes, true)
                );
            }

            return $collection->loadMissing(['attribute.type', 'translations'])->map($transform)->values();
        }

        $query = $this->entityQuery();

        if (method_exists($query->getModel(), 'scopeFiltered')) {
            $query->filtered();
        }

        $query->when(
            $codes,
            fn ($q) => $q->whereHas('attribute', fn ($q) => $q->whereIn('code', $codes)),
            fn ($q) => $q->whereHas('attribute'),
        )->with([
            'attribute.type', 'attribute.group.translations',
            'attribute.translations', 'attribute.enums.translations', 'translations',
        ]);

        return $paginated ? $query->paginate($paginated)->through($transform) : $query->get()->map($transform);
    }

    /** Return memoized search index data. */
    public function indexData(): array
    {
        return $this->indexData ??= $this->buildIndexData();
    }

    /** Get available attributes Builder for the current entity. */
    public function query(array $params = []): ?Builder
    {
        return $this->resolveEntity()->getAvailableAttributesQuery($params);
    }

    /** Return the attribute query builder. */
    public function builder(): AttributeQueryBuilder
    {
        return $this->builder ??= new AttributeQueryBuilder(
            $this->enumRegistry,
            fn (string $code) => $this->field($code),
            fn (string $code) => $this->entity?->getEntityType()
                ?? ($this->fields[$code] ?? null)?->attribute()->getAttribute('entity_type'),
        );
    }

    protected static function buildFromCollection(Collection $attributes): static
    {
        $attributes->loadMissing('type');
        $instance = app(static::class, ['entity' => null]);

        foreach ($attributes as $attribute) {
            $instance->fields[$attribute->code] = $instance->makeField($attribute);
        }

        $instance->schemaLoaded = true;

        return $instance;
    }

    protected static function buildFromAttributable(Attributable $entity, SchemaRegistry $registry): static
    {
        $parameters = $entity->attributeScopeIds();
        $sorted = $parameters;
        sort($sorted);

        $parametersKey = empty($parameters) ? 'default' : md5(json_encode($sorted, JSON_THROW_ON_ERROR));
        $registryKey = "{$entity->getEntityType()}:{$parametersKey}";

        $attributes = $registry->resolve(
            $registryKey,
            fn () => $entity->getAvailableAttributesQuery($parameters)?->get() ?? new EloquentCollection()
        );

        return static::buildFromCollection($attributes);
    }

    protected function hydrate(Collection $attributes): void
    {
        $records = $this->storedValues($attributes->pluck('id')->all())->groupBy('attribute_id');

        foreach ($attributes as $attribute) {
            $field = $this->makeField($attribute);
            $field->hydrate($records->get($attribute->id, collect()));
            $this->fields[$attribute->code] = $field;
        }
    }

    /**
     * Stored values for the given attributes, reusing `attribute_values` when already loaded.
     *
     * @param list<int> $attributeIds
     */
    private function storedValues(array $attributeIds): Collection
    {
        if (! $this->entity) {
            return collect();
        }

        if (($loaded = $this->loadedValues()) !== null) {
            return $loaded->whereIn('attribute_id', $attributeIds)->values();
        }

        return $this->entityQuery()
            ->whereIn('attribute_id', $attributeIds)
            ->with(['translations' => fn ($q) => $q->withoutGlobalScope(ActiveLocaleScope::class)])
            ->get();
    }

    /**
     * Rows the entity already carries in memory, extended with the parent rows a variant inherits.
     *
     * @return Collection<int, Model>|null Null when nothing is loaded and the database has to be read
     */
    private function loadedValues(): ?Collection
    {
        if (! $this->entity instanceof Model || ! $this->entity->relationLoaded('attribute_values')) {
            return null;
        }

        $own = $this->entity->attribute_values;
        $parent = $this->entity->attributeParent();

        if (! $parent instanceof Model) {
            return $own;
        }

        if (! $parent->relationLoaded('attribute_values')) {
            return null;
        }

        $filled = $own->filter(static fn (Model $value): bool => $value->hasValue());
        $overridden = $filled->pluck('attribute_id')->all();

        return $filled->concat($parent->attribute_values->loadMissing('attribute')->filter(
            static fn (Model $value): bool => (bool) $value->attribute?->getAttribute('inherit_from_parent')
                && ! in_array($value->getAttribute('attribute_id'), $overridden, true)
        ));
    }

    protected function entityQuery(): Builder
    {
        return Eav::$entityAttributeModel::query()->forEntity($this->resolveEntity());
    }

    protected function resolveEntity(): Attributable
    {
        return $this->entity ?? ($this->entityClass ? new ($this->entityClass)() : throw MissingEntityException::forManager());
    }

    private function makeField(Attribute $attribute): Field
    {
        return $this->fieldFactory->make($attribute)->forEntity($this->entity);
    }

    private function persister(): AttributePersister
    {
        return $this->persister ??= app(AttributePersister::class, ['entity' => $this->resolveEntity()]);
    }

    private function buildIndexData(): array
    {
        if (! $this->entity) {
            return [];
        }

        $attributes = $this->indexableValues()
            ->groupBy('attribute_id')
            ->reduce(function (array $carry, Collection $group) {
                $field = $this->makeField($group->first()->attribute);
                $field->hydrate($group);

                return $carry + $field->indexData();
            }, []);

        return $attributes ? ['attributes' => $attributes] : [];
    }

    /**
     * Attribute values that belong in the search index.
     *
     * @return Collection<int, Model>
     */
    private function indexableValues(): Collection
    {
        $indexable = static fn (?Attribute $attribute): bool =>
        (bool) ($attribute?->getAttribute('searchable') || $attribute?->getAttribute('filterable'));

        if (($loaded = $this->loadedValues()) !== null) {
            return $loaded->filter(fn (Model $value): bool => $indexable($value->attribute))->values();
        }

        return $this->entityQuery()
            ->whereHas('attribute', fn ($q) => $q->whereAny(['searchable', 'filterable'], true))
            ->with([
                'attribute',
                'attribute.enums.translations' => fn ($q) => $q->withoutGlobalScope(ActiveLocaleScope::class),
                'translations' => fn ($q) => $q->withoutGlobalScope(ActiveLocaleScope::class),
            ])
            ->get();
    }
}
