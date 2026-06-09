<?php

namespace Jurager\Eav\Managers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\MissingEntityException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Support\AttributePersister;
use Jurager\Eav\Support\AttributeQueryBuilder;
use Jurager\Eav\Support\EavModels;

/**
 * Coordinates attribute schema loading, in-memory value changes, and persistence.
 *
 * Three usage modes:
 *   Entity instance: AttributeManager::for($product)        values + schema
 *   Entity class:    AttributeManager::for(Product::class)  schema only
 *   Entity type:     AttributeManager::for('product')       schema only
 */
class AttributeManager
{
    /** @var array<string, Field> */
    protected array $fields = [];

    /** @var array<string, Collection<int, mixed>> */
    protected array $cachedAttributes = [];

    /** @var array<string, bool> Tracks which schema param keys are fully loaded into. */
    private array $schemaLoaded = [];

    private ?AttributePersister $persister = null;

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

    private ?FieldFactory $fieldFactory = null;

    private ?EnumRegistry $enumRegistry = null;

    private ?AttributeQueryBuilder $builder = null;

    /** FQCN stored for schema-only managers created from a class string. */
    protected ?string $entityClass = null;

    /**
     * @param  Collection<int, mixed>|null  $preloadedAttributes
     */
    public function __construct(
        protected ?Attributable $entity = null,
        ?Collection $preloadedAttributes = null,
    ) {
        if ($preloadedAttributes !== null) {
            $this->cachedAttributes['default'] = $preloadedAttributes;
        }
    }

    /**
     * Create a manager for an entity instance, FQCN, or morph-map key.
     *
     * @throws InvalidConfigurationException
     */
    public static function for(string|Attributable $entity): static
    {
        if ($entity instanceof Attributable) {
            return new static($entity);
        }

        if (class_exists($entity)) {
            if (! is_subclass_of($entity, Attributable::class)) {
                throw InvalidConfigurationException::missingAttributableContract($entity);
            }

            // Schema-only: resolve entity type from a transient instance but do not
            // store the instance - this prevents accidentally persisting values with
            // entity_id = null when the returned manager is used for writes.
            $instance = new $entity();

            $manager = new static(null, EavModels::query('attribute')
                ->forEntity($instance->attributeEntityType())
                ->withRelations()
                ->get());

            $manager->entityClass = $entity;

            return $manager;
        }

        // Morph-map key (e.g. 'product') - schema-only, no entity instance.
        return new static(null, EavModels::query('attribute')
            ->forEntity($entity)
            ->withRelations()
            ->get());
    }

    /**
     * Return a schema-only manager for an entity or a pre-loaded attribute collection.
     *
     * @param  Attributable|Collection<int, Attribute>  $entityOrAttributes
     *
     * @throws JsonException
     */
    public static function schema(Attributable|Collection $entityOrAttributes): static
    {
        if ($entityOrAttributes instanceof Collection) {
            return static::buildFromCollection($entityOrAttributes);
        }

        return static::buildFromAttributable($entityOrAttributes, app(SchemaRegistry::class));
    }

    /**
     * Build a schema-only manager from a pre-loaded attribute collection.
     *
     * @param  Collection<int, Attribute>  $attributes
     *
     */
    private static function buildFromCollection(Collection $attributes): static
    {
        $attributes->loadMissing('type');

        $instance = new static(null, $attributes);

        foreach ($attributes as $attribute) {
            $instance->fields[$attribute->code] = $instance->makeField($attribute);
        }

        return $instance;
    }

    /**
     * Build a Field for the given Attribute and bind the entity context when present.
     */
    private function makeField(Attribute $attribute): Field
    {
        return $this->fieldFactory()->make($attribute)->forEntity($this->entity);
    }

    /**
     * Build a schema-only manager for an entity, using the SchemaRegistry to avoid
     * repeated DB queries across multiple calls within the same process.
     *
     * @throws JsonException
     */
    private static function buildFromAttributable(Attributable $entity, SchemaRegistry $registry): static
    {
        $parameters = $entity->attributeParameters();

        $entityType = $entity->attributeEntityType();

        $parametersKey = empty($parameters) ? 'default' : md5(json_encode($parameters, JSON_THROW_ON_ERROR));

        $registryKey = $entityType.':'.$parametersKey;

        // Resolve from cache or query the DB and cache for the process lifetime.
        // Call availableAttributesQuery() directly on the entity rather than
        // constructing a throwaway AttributeManager just to call query() on it.
        $attributes = $registry->resolve(
            $registryKey,
            fn () => $entity->availableAttributesQuery($parameters)?->get() ?? collect()
        );

        return static::buildFromCollection($attributes);
    }

    /**
     * Persist attribute values for multiple entities in chunked batches.
     *
     * Each entity is persisted in its own transaction. If $onError is provided,
     * a failing entity's exception is passed to the callback and processing continues;
     * without $onError the exception is re-thrown.
     *
     * @param  Collection<int, array{entity: Attributable, data: array<string, mixed>}>  $batch
     * @param  static|null  $prebuiltSchema  Shared schema for all entities; skips per-entity DB queries when provided.
     * @param  callable(\Throwable, Attributable): void|null  $onError  Receives the exception and the failing entity.
     *
     * @throws JsonException
     */
    public static function sync(Collection $batch, ?self $prebuiltSchema = null, int $chunkSize = 500, ?callable $onError = null): void
    {
        if ($batch->isEmpty()) {
            return;
        }

        foreach ($batch->chunk(max(1, $chunkSize)) as $chunk) {
            $persister = new AttributePersister();

            foreach ($chunk as $item) {
                $entity = $item['entity'];
                $fields = ($prebuiltSchema ?? static::schema($entity))->fill($item['data']);

                if ($fields->isNotEmpty()) {
                    $persister->add($entity, $fields);
                }
            }

            $persister->flush($onError);
        }
    }

    /**
     * Load all attribute schemas into $this->fields. Safe to call multiple times.
     *
     * @throws JsonException
     */
    public function ensureSchema(): static
    {
        $params = $this->entity?->attributeParameters() ?? [];
        $key = $this->schemaParamsKey($params);

        if (isset($this->schemaLoaded[$key])) {
            return $this;
        }

        $attributes = $this->resolveAttributes($params);

        $attributes
            ->reject(fn ($attr) => isset($this->fields[$attr->code]))
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->makeField($attr));

        $this->schemaLoaded[$key] = true;

        return $this;
    }

    /**
     * Batch-load and hydrate specific fields by code; already-loaded codes are skipped.
     *
     * @param  array<string>  $codes
     *
     * @throws JsonException
     */
    public function ensureFields(array $codes): void
    {
        $codes = array_diff($codes, array_keys($this->fields));

        if (empty($codes)) {
            return;
        }

        $attributes = $this->resolveAttributes()->whereIn('code', $codes);

        if ($attributes->isEmpty()) {
            return;
        }

        $this->hydrate($attributes);
    }

    /**
     * Return all loaded Field objects keyed by attribute code.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Return a hydrated Field by code, loading it on demand if needed.
     *
     * @throws JsonException
     */
    public function field(string $code): ?Field
    {
        if (isset($this->fields[$code])) {
            return $this->fields[$code];
        }

        $attribute = $this->resolveAttributes()->firstWhere('code', $code)
            ?? ($this->entity
                ? EavModels::query('attribute')
                    ->forEntity($this->entity->attributeEntityType())
                    ->withRelations()
                    ->firstWhere('code', $code)
                : null);

        if (! $attribute) {
            return null;
        }

        $this->hydrate(collect([$attribute]));

        return $this->fields[$code] ?? null;
    }

    /**
     * Return the typed value for an attribute.
     *
     * @throws JsonException
     */
    public function value(string $code, ?int $localeId = null): mixed
    {
        return $this->field($code)?->value($localeId);
    }

    /**
     * Set a value in memory without persisting.
     *
     * @throws JsonException
     */
    public function set(string $code, mixed $value, ?int $localeId = null): static
    {
        $this->field($code)?->set($value, $localeId);

        return $this;
    }

    /**
     * Persist a single attribute value.
     *
     * @throws JsonException
     */
    public function save(string $code): void
    {
        $field = $this->field($code);

        if (! $field?->isFilled()) {
            return;
        }

        $this->persister()->save($field);
    }

    /**
     * Persist the given fields, leaving existing rows untouched.
     *
     * @param  array<string, Field>  $fields
     */
    public function attach(array $fields): void
    {
        foreach ($fields as $code => $field) {
            $this->fields[$code] = $field;
        }

        $this->persister()->persist(
            collect($fields)->filter(fn (Field $f) => $f->isFilled()),
        );
    }

    /**
     * Replace all entity_attribute rows with the given fields.
     *
     * @param  array<string, Field>  $fields
     */
    public function replace(array $fields): void
    {
        $this->fields = $fields;

        $this->persister()->replace(
            collect($this->fields)->filter(fn (Field $f) => $f->isFilled()),
        );
    }

    /**
     * Delete entity_attribute rows for the given attribute IDs.
     *
     * @param  array<int>  $ids
     */
    public function detach(array $ids): void
    {
        $this->persister()->detach($ids);
    }

    /**
     * Fill fields from raw data, reusing the cached schema.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Field>
     *
     * @throws JsonException
     */
    public function fill(array $data): Collection
    {
        $this->ensureSchema();

        $filled = collect();

        foreach ($data as $code => $value) {
            if (! isset($this->fields[$code])) {
                continue;
            }

            // Clone so state does not leak between entities sharing the same schema.
            $field = clone $this->fields[$code];

            if ($field->fill($value)) {
                $filled->push($field);
            }
        }

        return $filled;
    }

    /**
     * Return entity_attribute records with a resolved typed `value` property.
     *
     * @param  array<string>|null  $codes
     * @param  int|null  $paginated  When set, returns a paginator instead of a collection.
     * @return Collection<int, Model>|LengthAwarePaginator
     */
    public function values(?array $codes = null, ?int $paginated = null): Collection|LengthAwarePaginator
    {
        $transform = fn (Model $model): Model => tap($model, function ($model) {
            $model->value = $this->makeField($model->attribute)->read($model);
        });

        // Use pre-loaded relation to avoid N+1 when the caller eager-loads attribute_values.
        if ($this->entity instanceof Model && $this->entity->relationLoaded('attribute_values')) {
            $collection = $this->entity->attribute_values;

            if ($codes !== null) {
                $collection = $collection->filter(
                    fn ($ea) => $ea->relationLoaded('attribute')
                        && in_array($ea->attribute->code ?? null, $codes, true)
                );
            }

            return $collection->map($transform)->values();
        }

        $query = $this->entityQuery();

        if (method_exists($query->getModel(), 'scopeFiltered')) {
            $query->filtered();
        }

        $query->when(
            $codes,
            fn ($q) => $q->whereHas('attribute', fn ($q) => $q->whereIn('code', $codes)),
            fn ($q) => $q->whereHas('attribute'),
        )
            ->with([
                'attribute.type',
                'attribute.group.translations',
                'attribute.translations',
                'attribute.enums.translations',
                'translations',
            ]);

        if ($paginated !== null) {
            return $query->paginate($paginated)->through($transform);
        }

        return $query->get()->map($transform);
    }

    /**
     * Return memoized search index data for all searchable attributes.
     *
     * @return array<string, mixed>
     */
    public function indexData(): array
    {
        return $this->indexData ??= $this->buildIndexData();
    }

    /**
     * Return available attributes Builder for the current entity.
     *
     * @param  array<string, mixed>  $params
     */
    public function query(array $params = []): ?Builder
    {
        return $this->resolveEntity()->availableAttributesQuery($params);
    }

    /**
     * Returns the entity instance, or a transient instance from the stored class for schema-only managers.
     * Do NOT use when you need entity->id (write operations).
     *
     * @throws MissingEntityException When neither an entity instance nor entityClass is available.
     */
    protected function resolveEntity(): Attributable
    {
        return $this->entity ?? ($this->entityClass ? new ($this->entityClass)() : throw MissingEntityException::forManager());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     *
     * @throws JsonException
     */
    protected function resolveAttributes(array $params = []): Collection
    {
        $key = $this->schemaParamsKey($params);

        return $this->cachedAttributes[$key] ??= $this->query($params)?->get() ?? collect();
    }

    /**
     * Create and hydrate Field instances from the given attribute records.
     *
     * @param  Collection<int, mixed>  $attributes
     *
     */
    protected function hydrate(Collection $attributes): void
    {
        /** @var Collection<int|string, Collection<int, object>> $records */
        $records = $this->entity
            ? $this->entityQuery()
                ->whereIn('attribute_id', $attributes->pluck('id')->all())
                ->with('translations')
                ->get()
                ->groupBy('attribute_id')
            : collect();

        foreach ($attributes as $attribute) {
            $field = $this->makeField($attribute);
            $field->hydrate($records->get($attribute->id, collect()));
            $this->fields[$attribute->code] = $field;
        }
    }

    /** Return a Builder scoped to entity_attribute rows for the current entity. */
    protected function entityQuery(): Builder
    {
        if ($this->entity === null) {
            throw MissingEntityException::forManager();
        }

        return EavModels::query('entity_attribute')
            ->where('entity_type', $this->entity->attributeEntityType())
            ->where('entity_id', $this->entity->id);
    }

    private function persister(): AttributePersister
    {
        if ($this->entity === null) {
            throw MissingEntityException::forManager();
        }

        return $this->persister ??= new AttributePersister($this->entity);
    }

    /** @return array<string, mixed> */
    private function buildIndexData(): array
    {
        if (! $this->entity) {
            return [];
        }

        $attributes = $this->entityQuery()
            ->whereHas('attribute', fn ($q) => $q->where('searchable', true)->orWhere('filterable', true))
            ->with(['attribute', 'attribute.enums.translations', 'translations'])
            ->get()
            ->groupBy('attribute_id')
            ->reduce(function (array $carry, Collection $group) {
                $field = $this->makeField($group->first()->attribute);
                $field->hydrate($group);

                return $carry + $field->indexData();
            }, []);

        return $attributes ? ['attributes' => $attributes] : [];
    }

    /** @throws JsonException */
    private function schemaParamsKey(array $params): string
    {
        return empty($params) ? 'default' : md5(json_encode($params, JSON_THROW_ON_ERROR));
    }

    private function fieldFactory(): FieldFactory
    {
        return $this->fieldFactory ??= app(FieldFactory::class);
    }

    private function enumRegistry(): EnumRegistry
    {
        return $this->enumRegistry ??= app(EnumRegistry::class);
    }

    public function builder(): AttributeQueryBuilder
    {
        return $this->builder ??= new AttributeQueryBuilder(
            $this->enumRegistry(),
            fn (string $code) => $this->field($code),
            fn (string $code) => $this->entity?->attributeEntityType()
                ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type,
        );
    }
}
