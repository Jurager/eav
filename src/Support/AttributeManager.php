<?php

namespace Jurager\Eav\Support;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeFieldRegistry;
use LogicException;

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

    protected AttributeFieldRegistry $fieldRegistry;

    private readonly ?AttributePersister $persister;

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

    /** @var array<string, true> */
    private array $loadedSchemaKeys = [];

    /**
     * Process-level schema cache shared across syncBatch() calls within the same PHP process.
     *
     * @var array<string, static>
     */
    private static array $schemaRegistry = [];

    /**
     * @param  Attributable|null  $entity  Concrete entity instance, or null for schema-only mode.
     * @param  Collection<int, mixed>|null  $preloadedAttributes  Pre-fetched attributes to skip the initial DB query.
     */
    public function __construct(
        protected ?Attributable $entity = null,
        ?Collection $preloadedAttributes = null,
    ) {
        $this->fieldRegistry = app(AttributeFieldRegistry::class);
        $this->persister = $entity !== null ? new AttributePersister($entity) : null;

        if ($preloadedAttributes !== null) {
            $this->cachedAttributes['default'] = $preloadedAttributes;
        }
    }

    /**
     * Create a manager for a concrete entity instance, class, or string entity type.
     *
     * @param  string|Attributable  $entity  Entity instance, FQCN, or morph-map key (e.g. 'product').
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
                throw new InvalidArgumentException("$entity must implement Attributable");
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
     * Build a schema-only manager from a pre-loaded attribute collection.
     *
     * Use when attribute definitions are already in memory (e.g. from a setup query at the
     * start of an import). Passing the result to syncBatch() as $prebuiltSchema skips all
     * per-entity schema DB queries.
     *
     * The collection must have the 'type' relation loaded.
     *
     * @param  Collection<int, Attribute>  $attributes
     */
    public static function fromAttributes(Collection $attributes): static
    {
        if (method_exists($attributes, 'loadMissing')) {
            $attributes->loadMissing('type');
        }

        $instance = new static(null, $attributes);

        foreach ($attributes as $attribute) {
            $instance->fields[$attribute->code] = $instance->fieldRegistry->make($attribute);
        }

        $instance->loadedSchemaKeys['default'] = true;

        return $instance;
    }

    /**
     * Persist attribute values for multiple entities in a chunked batch.
     *
     * When $prebuiltSchema is provided it is used for all entities — no per-entity schema
     * queries are executed (fast path for bulk imports).
     * Without it, schemas are loaded per unique (entity_type, params) combination and
     * cached in the process-level $schemaRegistry across calls.
     *
     * @param  Collection<int, array{entity: Attributable, data: array<string, mixed>}>  $batch
     * @param  static|null  $prebuiltSchema  Optional pre-built schema for all entities.
     * @param  int  $chunkSize  Number of entities per DB flush (default 500).
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public static function syncBatch(Collection $batch, ?self $prebuiltSchema = null, int $chunkSize = 500): void
    {
        if ($batch->isEmpty()) {
            return;
        }

        foreach ($batch->chunk(max(1, $chunkSize)) as $chunk) {
            static::persistChunk($chunk, $prebuiltSchema);
        }
    }

    /**
     * @param  Collection<int, array{entity: Attributable, data: array<string, mixed>}>  $chunk
     */
    private static function persistChunk(Collection $chunk, ?self $prebuiltSchema): void
    {
        $persister = new AttributePersister();

        foreach ($chunk as $item) {
            $entity = $item['entity'];
            $schema = $prebuiltSchema ?? static::schemaForEntity($entity);
            $fields = $schema->fill($item['data']);

            if ($fields->isNotEmpty()) {
                $persister->add($entity, $fields);
            }
        }

        $persister->flush();
    }

    private static function schemaForEntity(Attributable $entity): self
    {
        $cacheKey = static::schemaCacheKey($entity);

        if (! isset(static::$schemaRegistry[$cacheKey])) {
            static::$schemaRegistry[$cacheKey] = static::for($entity)->loadSchema();
        }

        return static::$schemaRegistry[$cacheKey];
    }

    /** @throws JsonException */
    private static function schemaCacheKey(Attributable $entity): string
    {
        return $entity->getAttributeEntityType().':'.md5(serialize($entity->getDefaultParameters()));
    }

    /**
     * Load all attribute schemas (without values) into $this->fields.
     * Skips codes already loaded. Safe to call multiple times.
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public function loadSchema(): static
    {
        $params = $this->entity?->getDefaultParameters() ?? [];
        $schemaKey = $this->schemaParamsKey($params);

        if (isset($this->loadedSchemaKeys[$schemaKey])) {
            return $this;
        }

        $this->resolveAttributes($params)
            ->reject(fn ($attr) => isset($this->fields[$attr->code]))
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->fieldRegistry->make($attr));

        $this->loadedSchemaKeys[$schemaKey] = true;

        return $this;
    }

    /**
     * Batch-load and hydrate specific fields by attribute code. Already-loaded codes are skipped.
     *
     * @param  array<string>  $codes
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function loadFields(array $codes): void
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
     * Return all currently loaded Field objects keyed by attribute code.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Return a single hydrated Field by attribute code, loading it on demand if needed.
     * Returns null if no attribute with that code exists for this entity.
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
     * Return the typed value of a single attribute.
     * Pass $localeId to get a locale-specific value for localizable fields.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function value(string $code, ?int $localeId = null): mixed
    {
        return $this->field($code)?->value($localeId);
    }

    /**
     * Set an attribute value in memory without persisting.
     * Chain calls and then call save() or sync() to persist.
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
     * Persist a single attribute value to the database.
     * Silently returns when the field is not found or has no value.
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

        $this->persister()->saveField($field);
    }

    /**
     * Persist the given fields, merging with any existing entity_attribute rows.
     * Only the provided fields are written; other attributes are left untouched.
     *
     * @param  array<string, Field>  $fields  Keyed by attribute code.
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
     * Persist the given fields and delete all existing entity_attribute rows not in this set.
     * Use for a full replace of the entity's attribute values.
     *
     * @param  array<string, Field>  $fields  Keyed by attribute code.
     */
    public function sync(array $fields): bool
    {
        $this->fields = $fields;

        $this->persister()->syncFields(
            collect($this->fields)->filter(fn (Field $f) => $f->isFilled()),
        );

        return true;
    }

    /**
     * Delete entity_attribute rows for the given attribute IDs.
     *
     * @param  array<int>  $ids  Attribute IDs (not record IDs).
     */
    public function detach(array $ids): void
    {
        $this->persister()->detachByAttributeIds($ids);
    }

    /**
     * Fill fields from raw data, reusing the cached schema.
     *
     * Each field is cloned from the schema so state does not leak between entities.
     * Call loadSchema() (or field()) at least once before using this method in batch mode.
     *
     * @param  array<string, mixed>  $data  Raw attribute values keyed by attribute code.
     * @return Collection<int, Field> Filled Field instances (unfilled fields excluded).
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public function fill(array $data): Collection
    {
        $this->loadSchema();

        $filled = collect();

        foreach ($data as $code => $value) {
            if (! isset($this->fields[$code])) {
                continue;
            }

            $field = clone $this->fields[$code];

            if ($field->fill($value)) {
                $filled->push($field);
            }
        }

        return $filled;
    }

    /**
     * Return entity_attribute records with resolved typed values.
     * Each record gets a virtual `value` property set to the typed field value.
     *
     * @param  array<string>|null  $codes  Limit to these attribute codes; null returns all.
     * @return Collection<int, Model>
     *
     * @throws BindingResolutionException
     */
    public function values(?array $codes = null): Collection
    {
        return $this->valuesQuery($codes)->get()->map($this->valueMapper());
    }

    /**
     * Return a closure that resolves and assigns the typed value on an entity_attribute record.
     * Useful when mapping over paginated results: ->through($manager->valueMapper()).
     *
     * @return Closure(Model): Model
     */
    public function valueMapper(): Closure
    {
        return function ($record) {
            $record->value = $this->fieldRegistry->make($record->attribute)->fromRecord($record);

            return $record;
        };
    }

    /**
     * Return an eager-loaded Builder for entity_attribute records of the current entity.
     *
     * @param  array<string>|null  $codes  Limit to these attribute codes; null returns all.
     */
    public function valuesQuery(?array $codes = null): Builder
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
     * Cached for the lifetime of the manager instance.
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
     * Returns null if the attribute is not numeric or cannot be resolved.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function aggregate(string $code, string $aggregate): ?float
    {
        if (! in_array($aggregate, ['sum', 'avg', 'min', 'max'], true)) {
            throw new InvalidArgumentException("Invalid aggregate '$aggregate'. Allowed: sum, avg, min, max.");
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
     * Return a Builder on the entity model scoped to entities whose attribute matches the given condition.
     * Returns null if the attribute or entity type cannot be resolved.
     *
     * @param  string  $operator  =, !=, >, <, >=, <=, like, in, not_in, null, not_null, between.
     * @param  int|null  $localeId  Restrict localizable field search to this locale.
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
     * For localizable fields, matches against entity_translations.label instead of the value column.
     *
     * Returns null when the attribute or entity type cannot be resolved.
     *
     * @param  string  $operator  =, !=, >, <, >=, <=, like, in, not_in, null, not_null, between.
     * @param  int|null  $localeId  For localizable fields, restrict to this locale.
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
     * Find a single entity whose attribute matches the given value.
     * Supports an optional operator as the second argument.
     *
     * @param  mixed  $operatorOrValue  Operator string or value when operator is '='.
     * @param  int|null  $localeId  Restrict localizable field search to this locale.
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
     * Find all entities whose attribute matches the given value.
     *
     * @param  mixed  $operatorOrValue  Operator string or value when operator is '='.
     * @param  int|null  $localeId  Restrict localizable field search to this locale.
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
     * Return the raw Builder for available attributes scoped to the current entity.
     *
     * @param  array<string, mixed>  $params  Extra filter parameters (e.g. category IDs).
     */
    public function attributesQuery(array $params = []): ?Builder
    {
        return $this->entityOrFail()->getAvailableAttributesQuery($params);
    }

    /**
     * Return the current entity, throwing when the manager is in schema-only mode.
     *
     * @throws LogicException
     */
    protected function entityOrFail(): Attributable
    {
        return $this->entity ?? throw new LogicException('Entity is required. Use AttributeManager::for($entity).');
    }

    /**
     * Return the available attribute collection for the given params, with in-memory caching.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     *
     * @throws JsonException
     */
    protected function resolveAttributes(array $params = []): Collection
    {
        $key = $this->schemaParamsKey($params);

        return $this->cachedAttributes[$key] ??= $this->attributesQuery($params)?->get() ?? collect();
    }

    /**
     * Create Field instances for the given attributes, hydrate them with stored values,
     * and register them in $this->fields. Skips the DB query in schema-only mode.
     *
     * @param  Collection<int, mixed>  $attributes
     *
     * @throws BindingResolutionException
     */
    protected function hydrate(Collection $attributes): void
    {
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

    /**
     * Return a base Builder scoped to entity_attribute rows for the current entity.
     */
    protected function entityQuery(): Builder
    {
        $entity = $this->entityOrFail();

        return EavModels::query('entity_attribute')
            ->where('entity_type', $entity->getAttributeEntityType())
            ->where('entity_id', $entity->id);
    }

    /**
     * Return a Builder for searchable attribute rows with required relations eager-loaded.
     */
    protected function indexQuery(): Builder
    {
        return $this->entityQuery()
            ->whereHas('attribute', fn ($q) => $q->where('searchable', true))
            ->with(['attribute', 'attribute.enums.translations', 'translations']);
    }

    private function persister(): AttributePersister
    {
        return $this->persister ?? throw new LogicException('Entity is required. Use AttributeManager::for($entity).');
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

    /**
     * Apply a comparison operator to a Builder column.
     * Used for both localizable (entity_translations.label) and non-localizable (value_*) columns.
     */
    private function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'like' => $query->where($column, 'LIKE', "%$value%"),
            'in' => $query->whereIn($column, (array) $value),
            'not_in' => $query->whereNotIn($column, (array) $value),
            'null' => $query->whereNull($column),
            'not_null' => $query->whereNotNull($column),
            'between' => $query->whereBetween($column, $value),
            default => $query->where($column, $operator, $value),
        };
    }

    /**
     * Parse the overloaded ($operatorOrValue, $value) signature used by findBy / findAllBy.
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

    /**
     * Resolve the entity type string for a given attribute code.
     *
     * @throws JsonException
     */
    private function resolveEntityType(string $code): ?string
    {
        return $this->entity?->getAttributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;
    }
}
