<?php

namespace Jurager\Eav\Managers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\MissingEntityException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
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
    private const array OPERATORS = [
        '=', '!=', '>', '<', '>=', '<=', 'like', 'in', 'not_in', 'null', 'not_null', 'between',
    ];

    /** @var array<string, Field> */
    protected array $fields = [];

    /** @var array<string, Collection<int, mixed>> */
    protected array $cachedAttributes = [];

    protected FieldTypeRegistry $fieldRegistry;

    private readonly ?AttributePersister $persister;

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

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
     * @throws InvalidArgumentException
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

            return new static(new $entity());
        }

        // String entity type (e.g. 'product') — schema-only, no entity instance.
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
        $parameters = $entity->getDefaultParameters();

        $entityType = $entity->getAttributeEntityType();

        $parametersKey = empty($parameters) ? 'default' : md5(json_encode($parameters, JSON_THROW_ON_ERROR));

        $registryKey = $entityType . ':' . $parametersKey;

        // Resolve from cache or query the DB and cache for the process lifetime.
        $attributes = $registry->resolve(
            $registryKey,
            fn () => static::for($entity)->query($parameters)?->get() ?? collect()
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
        $params = $this->entity?->getDefaultParameters() ?? [];

        $this->resolveAttributes($params)
            ->reject(fn ($attr) => isset($this->fields[$attr->code]))
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->fieldRegistry->make($attr));

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

        $attribute = $this->resolveAttributes()->firstWhere('code', $code);

        if (! $attribute) {
            return null;
        }

        $this->hydrate(collect([$attribute]));

        return $this->fields[$code];
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
     * @param array<string, Field> $fields
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
     *
     */
    public function values(?array $codes = null, ?int $paginated = null): Collection|LengthAwarePaginator
    {
        $query = $this->valuesQuery($codes);

        $transform = fn (Model $model): Model => tap($model, function ($model) {
            $model->value = $this->fieldRegistry->make($model->attribute)->from($model);
        });

        if ($paginated !== null) {
            return $query->paginate($paginated)->through($transform);
        }

        return $query->get()->map($transform);
    }

    /** @param  array<string>|null  $codes */
    private function valuesQuery(?array $codes = null): Builder
    {
        return $this->entityQuery()
            ->when($codes, fn ($q) => $q->whereHas('attribute', fn ($q) => $q->whereIn('code', $codes)))
            ->with([
                'attribute.type',
                'attribute.group.translations',
                'attribute.translations',
                'attribute.enums.translations',
                'translations',
            ]);
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
     * Return distinct stored values for a given attribute across all entities of this type.
     *
     * @return Collection<int, mixed>
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function distinctValues(string $code): Collection
    {
        $field = $this->field($code);
        $entityType = $this->resolveEntityType($code);

        if (! $field || ! $entityType) {
            return collect();
        }

        return EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->whereNotNull($field->column())
            ->distinct()
            ->pluck($field->column());
    }

    /**
     * Run a SQL aggregate (sum, avg, min, max) over a numeric attribute column.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function aggregate(string $code, string $aggregate): ?float
    {
        if (! in_array($aggregate, ['sum', 'avg', 'min', 'max'], true)) {
            throw new \InvalidArgumentException("Invalid aggregate '$aggregate'. Allowed: sum, avg, min, max.");
        }

        $field = $this->field($code);
        $entityType = $this->resolveEntityType($code);

        if (! $field || ! $entityType) {
            return null;
        }

        $col = $field->column();

        if (! in_array($col, [Field::STORAGE_FLOAT, Field::STORAGE_INTEGER], true)) {
            return null;
        }

        $result = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->whereNotNull($col)
            ->{$aggregate}($col);

        return $result !== null ? (float) $result : null;
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
     * Find a single entity by attribute value. Accepts an optional operator as the second argument.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function findBy(string $code, mixed $operatorOrValue, mixed $value = null, ?int $localeId = null): ?Model
    {
        [$operator, $value] = $this->normalizeOperator($operatorOrValue, $value);

        return $this->attributeQuery($code, $value, $operator, $localeId)?->first();
    }

    /**
     * Find all entities by attribute value. Accepts an optional operator as the second argument.
     *
     * @return Collection<int, Model>
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function findAllBy(string $code, mixed $operatorOrValue, mixed $value = null, ?int $localeId = null): Collection
    {
        [$operator, $value] = $this->normalizeOperator($operatorOrValue, $value);

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
        return $this->entityOrFail()->getAvailableAttributesQuery($params);
    }

    /** @throws LogicException */
    protected function entityOrFail(): Attributable
    {
        return $this->entity ?? throw MissingEntityException::forManager();
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
        $entity = $this->entityOrFail();

        return EavModels::query('entity_attribute')
            ->where('entity_type', $entity->getAttributeEntityType())
            ->where('entity_id', $entity->id);
    }

    /** Return a Builder for searchable attribute rows with required relations eager-loaded. */
    protected function indexQuery(): Builder
    {
        return $this->entityQuery()
            ->whereHas('attribute', fn ($q) => $q->where('searchable', true))
            ->with(['attribute', 'attribute.enums.translations', 'translations']);
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

        $attributes = $this->indexQuery()->get()
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
            'like' => $query->where($column, 'LIKE', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value).'%'),
            'in' => $query->whereIn($column, (array) $value),
            'not_in' => $query->whereNotIn($column, (array) $value),
            'null' => $query->whereNull($column),
            'not_null' => $query->whereNotNull($column),
            'between' => $query->whereBetween($column, $value),
            default => $query->where($column, $operator, $value),
        };
    }

    /**
     * Parse the overloaded ($operatorOrValue, $value) signature.
     *
     * @return array{string, mixed}
     */
    private function normalizeOperator(mixed $operatorOrValue, mixed $value): array
    {
        if (is_string($operatorOrValue) && in_array($operatorOrValue, self::OPERATORS, true)) {
            return [$operatorOrValue, $value];
        }

        return ['=', $operatorOrValue];
    }

    /** @throws JsonException */
    private function schemaParamsKey(array $params): string
    {
        return empty($params) ? 'default' : md5(json_encode($params, JSON_THROW_ON_ERROR));
    }

    /** @throws JsonException */
    private function resolveEntityType(string $code): ?string
    {
        return $this->entity?->getAttributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;
    }

}
