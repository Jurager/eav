<?php

namespace Jurager\Eav\Managers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\MissingEntityException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Support\AttributePersister;
use Jurager\Eav\Support\EavModels;

/**
 * Coordinates attribute schema loading, in-memory value changes, and persistence.
 *
 * Three usage modes:
 *   Entity instance: AttributeManager::for($product)       — values + schema
 *   Entity class:    AttributeManager::for(Product::class)  — schema only
 *   Entity type:     AttributeManager::for('product')       — schema only
 */
class AttributeManager
{
    /** @var array<string, Field> */
    protected array $fields = [];

    /** @var array<string, Collection<int, mixed>> */
    protected array $cachedAttributes = [];

    /** @var array<string, bool> Tracks which schema param keys are fully loaded into. */
    private array $schemaLoaded = [];

    protected FieldTypeRegistry $fieldRegistry;

    private readonly ?AttributePersister $persister;

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

    /** FQCN stored for schema-only managers created from a class string. */
    protected ?string $entityClass = null;

    /**
     * @param  Collection<int, mixed>|null  $preloadedAttributes
     */
    public function __construct(
        protected ?Attributable $entity = null,
        ?Collection $preloadedAttributes = null,
    ) {
        $this->fieldRegistry = app(FieldTypeRegistry::class);
        $this->persister = $entity !== null ? new AttributePersister($entity) : null;

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
            // store the instance — this prevents accidentally persisting values with
            // entity_id = null when the returned manager is used for writes.
            $instance = new $entity();

            $manager = new static(null, EavModels::query('attribute')
                ->forEntity($instance->attributeEntityType())
                ->withRelations()
                ->get());

            $manager->entityClass = $entity;

            return $manager;
        }

        // Morph-map key (e.g. 'product') — schema-only, no entity instance.
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
     * @throws BindingResolutionException
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
     * @throws BindingResolutionException
     */
    private static function buildFromCollection(Collection $attributes): static
    {
        $attributes->loadMissing('type');

        $instance = new static(null, $attributes);

        foreach ($attributes as $attribute) {
            $instance->fields[$attribute->code] = $instance->fieldRegistry->make($attribute);
        }

        return $instance;
    }

    /**
     * Build a schema-only manager for an entity, using the SchemaRegistry to avoid
     * repeated DB queries across multiple calls within the same process.
     *
     * @throws BindingResolutionException
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
     * @throws BindingResolutionException
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
     * @throws BindingResolutionException
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
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->fieldRegistry->make($attr));

        if (count($this->fields) >= $attributes->count()) {
            $this->schemaLoaded[$key] = true;
        }

        return $this;
    }

    /**
     * Batch-load and hydrate specific fields by code; already-loaded codes are skipped.
     *
     * @param  array<string>  $codes
     *
     * @throws JsonException
     * @throws BindingResolutionException
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
     * @throws BindingResolutionException
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
     * @throws BindingResolutionException
     */
    public function value(string $code, ?int $localeId = null): mixed
    {
        return $this->field($code)?->value($localeId);
    }

    /**
     * Set a value in memory without persisting.
     *
     * @throws JsonException
     * @throws BindingResolutionException
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
     * @throws BindingResolutionException
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
    public function attach(array $fields): bool
    {
        foreach ($fields as $code => $field) {
            $this->fields[$code] = $field;
        }

        $this->persister()->persist(
            collect($fields)->filter(fn (Field $f) => $f->isFilled()),
        );

        return true;
    }

    /**
     * Replace all entity_attribute rows with the given fields.
     *
     * @param  array<string, Field>  $fields
     *
     * @throws \Throwable
     */
    public function replace(array $fields): bool
    {
        $this->fields = $fields;

        $this->persister()->replace(
            collect($this->fields)->filter(fn (Field $f) => $f->isFilled()),
        );

        return true;
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
     * @throws BindingResolutionException
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

        $transform = fn (Model $model): Model => tap($model, function ($model) {
            $model->value = $this->fieldRegistry->make($model->attribute)->from($model);
        });

        if ($paginated !== null) {
            return $query->paginate($paginated)->through($transform);
        }

        return $query->get()->map($transform);
    }

    /**
     * Return min/max ranges for filterable numeric attributes across a set of entity IDs.
     *
     * Keyed by attribute code: ['weight' => ['min' => 0.5, 'max' => 150.0], ...]
     *
     * @param  array<int>  $entityIds
     * @return array<string, array{min: float, max: float}>
     */
    public function numericRanges(array $entityIds): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $entityType = $this->resolveEntity()->attributeEntityType();
        $eaTable = EavModels::make('entity_attribute')->getTable();
        $attrTable = EavModels::make('attribute')->getTable();
        $typeTable = EavModels::make('attribute_type')->getTable();

        return EavModels::query('entity_attribute')
            ->join("$attrTable as _a", '_a.id', '=', "$eaTable.attribute_id")
            ->join("$typeTable as _at", '_at.id', '=', '_a.attribute_type_id')
            ->whereIn("$eaTable.entity_id", $entityIds)
            ->where("$eaTable.entity_type", $entityType)
            ->where('_a.filterable', true)
            ->where('_at.code', 'number')
            ->selectRaw("_a.code, MIN(COALESCE($eaTable.value_float, $eaTable.value_integer)) as range_min, MAX(COALESCE($eaTable.value_float, $eaTable.value_integer)) as range_max")
            ->groupBy('_a.id', '_a.code')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->code => ['min' => (float) $row->range_min, 'max' => (float) $row->range_max],
            ])
            ->toArray();
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
     * Return a Builder scoped to entities whose attribute matches the given condition.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function attributeQuery(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $entityType = $this->resolveEntityType($code);
        $modelClass = $entityType ? Relation::getMorphedModel($entityType) : null;
        $sub = $this->subquery($code, $value, $operator, $localeId);

        if (! $sub || ! $modelClass) {
            return null;
        }

        return $modelClass::query()->whereIn('id', $sub);
    }

    /**
     * Build a subquery on entity_attribute selecting entity_id rows matching the given condition.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function subquery(string $code, mixed $value = null, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $field = $this->field($code);
        $entityType = $this->resolveEntityType($code);

        if (! $field || ! $entityType) {
            return null;
        }

        $sub = EavModels::query('entity_attribute')
            ->select('entity_id')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id);

        if ($field->isLocalizable()) {
            // Localizable fields are matched against entity_translations.label.
            $sub->whereHas('translations', function ($q) use ($value, $operator, $localeId) {
                $this->applyOperator($q, 'entity_translations.label', $operator, $value);

                if ($localeId) {
                    $q->where('entity_translations.locale_id', $localeId);
                }
            });
        } else {
            $this->applyOperator($sub, $field->column(), $operator, $value);
        }

        return $sub;
    }

    /**
     * Find a single entity by attribute value.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function findBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Model
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->first();
    }

    /**
     * Find all entities by attribute value.
     *
     * @return Collection<int, Model>
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function findAllBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): Collection
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->get() ?? collect();
    }

    /**
     * Find all entities whose attribute value is in the given list.
     * Returns a Collection keyed by the raw attribute value for O(1) lookup.
     *
     * @param  array<int|string>  $values
     * @return Collection<string, Model>
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function findWhereIn(string $code, array $values): Collection
    {
        $field = $this->field($code);
        $entityType = $this->resolveEntityType($code);
        $modelClass = $entityType ? Relation::getMorphedModel($entityType) : null;

        if (! $field || ! $entityType || ! $modelClass) {
            return collect();
        }

        $column = $field->column();

        $rows = EavModels::query('entity_attribute')
            ->select(['entity_id', $column])
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->whereIn($column, $values)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $models = $modelClass::query()
            ->whereIn('id', $rows->pluck('entity_id'))
            ->get()
            ->keyBy('id');

        return $rows->mapWithKeys(function ($row) use ($models, $column): array {
            $model = $models[$row->entity_id] ?? null;

            return $model ? [(string) $row->{$column} => $model] : [];
        });
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

    /** @throws LogicException */
    /**
     * Returns the entity instance, or a transient instance from the stored class for schema-only managers.
     * Do NOT use when you need entity->id (write operations).
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
     * @throws BindingResolutionException
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
            $field = $this->fieldRegistry->make($attribute);
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
        return $this->persister ?? throw MissingEntityException::forManager();
    }

    /** @return array<string, mixed> */
    private function buildIndexData(): array
    {
        if (! $this->entity) {
            return [];
        }

        $attributes = $this->entityQuery()
            ->whereHas('attribute', fn ($q) => $q->where('searchable', true))
            ->with(['attribute', 'attribute.enums.translations', 'translations'])
            ->get()
            ->groupBy('attribute_id')
            ->flatMap(function (Collection $group) {
                $field = $this->fieldRegistry->make($group->first()->attribute);
                $field->hydrate($group);

                return $field->indexData();
            });

        return $attributes->isNotEmpty() ? ['attributes' => $attributes->all()] : [];
    }

    /** Apply a comparison operator to a query column. */
    private function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'like'          => $this->applyLike($query, $column, $value),
            '=', 'eq'       => $query->where($column, '=', $value),
            '!=', 'ne'      => $query->where($column, '!=', $value),
            'in'            => $query->whereIn($column, (array) $value),
            'nin', 'not_in' => $query->whereNotIn($column, (array) $value),
            'null'          => $query->whereNull($column),
            'not_null'      => $query->whereNotNull($column),
            'between'       => $query->whereBetween($column, $value),
            'not_between'   => $query->whereNotBetween($column, $value),
            default         => $query->where($column, $operator, $value),
        };
    }

    /** Wrap $value with % wildcards and escape LIKE special characters before binding. */
    private function applyLike(Builder $query, string $column, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        $query->whereRaw($column.' LIKE ?', ['%'.$escaped.'%']);
    }

    /** @throws JsonException */
    private function schemaParamsKey(array $params): string
    {
        return empty($params) ? 'default' : md5(json_encode($params, JSON_THROW_ON_ERROR));
    }

    /** @throws JsonException */
    private function resolveEntityType(string $code): ?string
    {
        return $this->entity?->attributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;
    }
}
