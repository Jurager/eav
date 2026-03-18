<?php

namespace Jurager\Eav\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Registry\AttributeFieldRegistry;
use LogicException;

/**
 * Coordinates attribute schema loading, in-memory value changes and persistence.
 *
 * Supports three usage modes:
 *   - Entity instance: AttributeManager::for($product)
 *   - Entity class:    AttributeManager::for(Product::class)
 *   - Entity type:     AttributeManager::for('product')  ← schema only, no values
 */
class AttributeManager
{
    /**
     * Hydrated Field objects keyed by attribute code.
     *
     * @var array<string, Field>
     */
    protected array $fields = [];

    /**
     * Available attribute collections keyed by parameter hash.
     *
     * @var array<string, Collection<int, mixed>>
     */
    protected array $cachedAttributes = [];

    protected AttributeFieldRegistry $fieldRegistry;

    private readonly ?AttributePersister $persister;

    /**
     * Memoized search index data for the current entity.
     *
     * @var array<string, mixed>|null
     */
    private ?array $indexData = null;

    /**
     * @param  Attributable|null              $entity               Concrete entity instance, or null for schema-only mode.
     * @param  Collection<int, mixed>|null    $preloadedAttributes  Pre-fetched attributes to skip the initial DB query.
     */
    public function __construct(
        protected ?Attributable $entity = null,
        ?Collection $preloadedAttributes = null
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
     * @throws InvalidArgumentException  If a class string is passed that does not implement Attributable.
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

        // String entity type (e.g. 'product') — load schema without an instance.
        return new static(null, EavModels::query('attribute')
            ->forEntity($entity)
            ->withRelations()
            ->get());
    }

    /**
     * Persist attribute values for multiple entities in a memory-safe, chunked batch.
     *
     * The attribute schema is loaded once per unique (entity_type, params) combination
     * and reused across all chunks — so 50,000 products sharing the same category set
     * produce exactly 1 schema query and ~7 DB queries per chunk, regardless of entity
     * or attribute count.
     *
     * Each chunk is flushed independently so the DB is never overwhelmed by a single
     * massive upsert. The default chunk size of 500 entities is a safe starting point;
     * tune it down if you have many attributes or long translations.
     *
     * Typical usage in an importer:
     *
     *   AttributeManager::syncBatch(collect([
     *       ['entity' => $product1, 'data' => ['color' => 'red',  'weight' => 1.5]],
     *       ['entity' => $product2, 'data' => ['color' => 'blue', 'weight' => 2.0]],
     *       // …
     *   ]));
     *
     * @param  Collection<int, array{entity: Attributable, data: array<string, mixed>}>  $batch
     * @param  int                                                                        $chunkSize  Number of entities per flush (default 500).
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public static function syncBatch(Collection $batch, int $chunkSize = 500): void
    {
        if ($batch->isEmpty()) {
            return;
        }

        // Schema cache persists across chunks — loaded once per unique (entity_type, params).
        $schemaCache = [];

        foreach ($batch->chunk($chunkSize) as $chunk) {
            $persister = new AttributePersister();

            foreach ($chunk as $item) {
                $entity = $item['entity'];
                $params = $entity->getDefaultParameters();
                $cacheKey = $entity->getAttributeEntityType() . ':' . md5(serialize($params));

                if (! isset($schemaCache[$cacheKey])) {
                    $schemaCache[$cacheKey] = static::for($entity);
                    $schemaCache[$cacheKey]->loadSchema();
                }

                $fields = $schemaCache[$cacheKey]->fill($item['data']);

                if ($fields->isNotEmpty()) {
                    $persister->add($entity, $fields);
                }
            }

            $persister->flush();
        }
    }

    /**
     * Load all attribute schemas (without values) into $this->fields.
     * Skips codes that are already loaded. Safe to call multiple times.
     *
     * @throws BindingResolutionException
     * @throws JsonException
     */
    public function loadSchema(): void
    {
        $params = $this->entity?->getDefaultParameters() ?? [];

        $this->resolveAttributes($params)
            ->reject(fn ($attr) => isset($this->fields[$attr->code]))
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->fieldRegistry->make($attr));
    }

    /**
     * Batch-load and hydrate specific fields by attribute code.
     * Already-loaded codes are skipped to avoid redundant queries.
     *
     * @param  array<string>  $codes  Attribute codes to load.
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
     * @param  string  $code  Attribute code.
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
     * Return the typed value of a single attribute for the current entity.
     * Pass $localeId to get a locale-specific value for localizable fields.
     *
     * @param  string    $code      Attribute code.
     * @param  int|null  $localeId  Locale ID, or null for the default locale.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function value(string $code, ?int $localeId = null): mixed
    {
        return $this->field($code)?->value($localeId);
    }

    /**
     * Set an attribute value in memory without persisting to the database.
     * Chain multiple calls and then call save() or sync() to persist.
     *
     * @param  string    $code      Attribute code.
     * @param  mixed     $value     Value to set.
     * @param  int|null  $localeId  Target locale for localizable fields.
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
     * Silently returns when the field is not found or has no value filled.
     *
     * @param  string  $code  Attribute code.
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
     * Existing rows for other attributes are left untouched (no deletions).
     *
     * @param  array<string, Field>  $fields  Keyed by attribute code.
     */
    public function attach(array $fields): bool
    {
        $this->fields = array_merge($this->fields, $fields);

        $this->persister()->persist(collect($this->fields)->filter(fn (Field $f) => $f->isFilled()));

        return true;
    }

    /**
     * Persist the given fields and delete all existing entity_attribute rows not in this set.
     * Use this for a full replace of the entity's attribute values.
     *
     * @param  array<string, Field>  $fields  Keyed by attribute code.
     */
    public function sync(array $fields): bool
    {
        $this->fields = $fields;

        $filled = collect($this->fields)->filter(fn (Field $f) => $f->isFilled());

        $this->persister()->syncFields($filled);

        return true;
    }

    /**
     * Delete entity_attribute rows for the given attribute IDs belonging to the current entity.
     *
     * @param  array<int>  $ids  Attribute IDs (not record IDs).
     */
    public function detach(array $ids): void
    {
        $this->persister()->detachByAttributeIds($ids);
    }

    /**
     * Fill fields from raw data, reusing this manager's cached schema.
     *
     * Each field is cloned from the schema so state does not leak between entities.
     * Call loadSchema() (or field()) at least once before using this method so the
     * schema is warm; subsequent calls are O(n fields) with no DB queries.
     *
     * @param  array<string, mixed>  $data  Raw attribute values keyed by attribute code.
     * @return Collection<int, Field>       Filled Field instances (unfilled fields are excluded).
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
     * Return entity_attribute records for the current entity with resolved typed values.
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
     * @return \Closure(Model): Model
     */
    public function valueMapper(): \Closure
    {
        return function ($record) {
            $record->value = $this->fieldRegistry->make($record->attribute)->fromRecord($record);

            return $record;
        };
    }

    /**
     * Return an eager-loaded Builder for entity_attribute records of the current entity,
     * with all relations needed to render attribute values and their metadata.
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
     * Return memoized search index data for all searchable attributes of the current entity.
     * Result is cached for the lifetime of the manager instance.
     *
     * @return array<string, mixed>  Empty array when entity is not set or has no searchable attributes.
     */
    public function indexData(): array
    {
        return $this->indexData ??= $this->buildIndexData();
    }

    /**
     * Return distinct stored values for a given attribute across all entities of this type.
     * Useful for building filter facets. Returns an empty collection if the attribute
     * cannot be resolved or does not exist.
     *
     * @param  string  $code  Attribute code.
     * @return Collection<int, mixed>
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function distinctValues(string $code): Collection
    {
        $field = $this->field($code);
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;

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
     * Run a SQL aggregate (sum, avg, min, max) over a numeric attribute column
     * across all entities of this type. Returns null if the attribute is not numeric
     * or cannot be resolved.
     *
     * @param  string  $code       Attribute code.
     * @param  string  $aggregate  One of: sum, avg, min, max.
     *
     * @throws InvalidArgumentException  If $aggregate is not one of the allowed values.
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function aggregate(string $code, string $aggregate): ?float
    {
        $field = $this->field($code);
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;

        if (! $field || ! $entityType) {
            return null;
        }

        $col = $field->column();

        if (! in_array($col, [Field::STORAGE_FLOAT, Field::STORAGE_INTEGER], true)) {
            return null;
        }

        if (! in_array($aggregate, ['sum', 'avg', 'min', 'max'], true)) {
            throw new InvalidArgumentException("Invalid aggregate '$aggregate'. Allowed: sum, avg, min, max.");
        }

        $result = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->whereNotNull($col)
            ->{$aggregate}($col);

        return $result !== null ? (float) $result : null;
    }

    /**
     * Return a Builder on the entity model scoped to entities whose attribute
     * with the given code matches the given value using the given operator.
     * Returns null if the attribute or entity type cannot be resolved.
     *
     * @param  string    $code      Attribute code.
     * @param  mixed     $value     Value to compare against.
     * @param  string    $operator  Comparison operator: =, !=, >, <, >=, <=, like, in, not_in, null, not_null, between.
     * @param  int|null  $localeId  Restrict localizable field search to this locale.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function attributeQuery(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;

        $modelClass = $entityType ? Relation::getMorphedModel($entityType) : null;
        $sub = $this->subquery($code, $value, $operator, $localeId);

        if (! $sub || ! $modelClass) {
            return null;
        }

        return $modelClass::query()->whereIn('id', $sub);
    }

    /**
     * Build a subquery on entity_attribute that selects entity_id rows matching the given condition.
     * Intended for use inside whereIn() scopes. Returns null when the attribute or entity type
     * cannot be resolved.
     *
     * Supported operators: =, !=, >, <, >=, <=, like, in, not_in, null, not_null, between.
     *
     * @param  string    $code      Attribute code.
     * @param  mixed     $value     Value(s) to compare against. For 'between' pass [min, max].
     * @param  string    $operator  Comparison operator (default '=').
     * @param  int|null  $localeId  For localizable fields, restrict to this locale.
     *
     * @throws JsonException
     * @throws BindingResolutionException
     */
    public function subquery(string $code, mixed $value = null, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $field = $this->field($code);
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->resolveAttributes()->firstWhere('code', $code)?->entity_type;

        if (! $field || ! $entityType) {
            return null;
        }

        $sub = EavModels::query('entity_attribute')
            ->select('entity_id')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id);

        if ($field->isLocalizable()) {
            $sub->whereHas('translations', function ($q) use ($value, $operator, $localeId) {
                $col = 'entity_translations.label';
                match ($operator) {
                    'like'     => $q->where($col, 'LIKE', "%{$value}%"),
                    'in'       => $q->whereIn($col, (array) $value),
                    'not_in'   => $q->whereNotIn($col, (array) $value),
                    'null'     => $q->whereNull($col),
                    'not_null' => $q->whereNotNull($col),
                    'between'  => $q->whereBetween($col, $value),
                    default    => $q->where($col, $operator, $value),
                };
                if ($localeId) {
                    $q->where('entity_translations.locale_id', $localeId);
                }
            });
        } else {

            $col = $field->column();

            match ($operator) {
                'like'     => $sub->where($col, 'LIKE', "%{$value}%"),
                'in'       => $sub->whereIn($col, (array) $value),
                'not_in'   => $sub->whereNotIn($col, (array) $value),
                'null'     => $sub->whereNull($col),
                'not_null' => $sub->whereNotNull($col),
                'between'  => $sub->whereBetween($col, $value),
                default    => $sub->where($col, $operator, $value),
            };
        }

        return $sub;
    }

    /**
     * Find a single entity whose attribute with the given code matches the given value.
     * Supports an optional operator as the second argument (findBy-operator-value style).
     *
     * @param  string    $code             Attribute code.
     * @param  mixed     $operatorOrValue  Operator string ('=', '>', 'like', …) or value when operator is '='.
     * @param  mixed     $value            Value to compare against; used only when $operatorOrValue is an operator.
     * @param  int|null  $localeId         Restrict localizable field search to this locale.
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
     * Find all entities whose attribute with the given code matches the given value.
     * Supports an optional operator as the second argument (findAllBy-operator-value style).
     *
     * @param  string    $code             Attribute code.
     * @param  mixed     $operatorOrValue  Operator string or value when operator is '='.
     * @param  mixed     $value            Value to compare against; used only when $operatorOrValue is an operator.
     * @param  int|null  $localeId         Restrict localizable field search to this locale.
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
     * Delegates to the entity's getAvailableAttributesQuery() contract method.
     *
     * @param  array<string, mixed>  $params  Extra filter parameters forwarded to the entity (e.g. category IDs).
     */
    public function attributesQuery(array $params = []): ?Builder
    {
        return $this->entityOrFail()->getAvailableAttributesQuery($params);
    }

    /**
     * Return the current entity, throwing when the manager was built in schema-only mode.
     *
     * @throws LogicException  When no entity was provided to the constructor.
     */
    protected function entityOrFail(): Attributable
    {
        return $this->entity ?? throw new LogicException('Entity is required. Use AttributeManager::for($entity).');
    }

    /**
     * Return the available attribute collection for the given params, using an in-memory cache.
     * The cache key is 'default' for empty params, or an md5 hash of the serialised params.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     *
     * @throws JsonException
     */
    protected function resolveAttributes(array $params = []): Collection
    {
        $key = empty($params) ? 'default' : md5(json_encode($params, JSON_THROW_ON_ERROR));

        return $this->cachedAttributes[$key] ??= $this->attributesQuery($params)?->get() ?? collect();
    }

    /**
     * Create Field instances for the given attributes, hydrate them with the entity's stored
     * values and register them in $this->fields. Skips the DB query in schema-only mode.
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
     * Used as the foundation for all per-entity queries in this manager.
     */
    protected function entityQuery(): Builder
    {
        $entity = $this->entityOrFail();

        return EavModels::query('entity_attribute')
            ->where('entity_type', $entity->getAttributeEntityType())
            ->where('entity_id', $entity->id);
    }

    /**
     * Return a Builder for entity_attribute rows that belong to searchable attributes,
     * with all relations required for index data assembly eager-loaded.
     * Only rows whose attribute has searchable = true are included (filtered at SQL level).
     */
    protected function indexQuery(): Builder
    {
        return $this->entityQuery()
            ->whereHas('attribute', fn ($q) => $q->where('searchable', true))
            ->with([
                'attribute',
                'attribute.enums.translations',
                'translations',
            ]);
    }

    private function persister(): AttributePersister
    {
        return $this->persister ?? throw new LogicException('Entity is required. Use AttributeManager::for($entity).');
    }

    /**
     * Build search index data by loading all searchable entity_attribute rows,
     * hydrating their Field instances and collecting the index-ready key/value pairs.
     * Returns an empty array when no entity is set or no searchable values exist.
     *
     * @return array<string, mixed>
     */
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
     * Parse the overloaded ($operatorOrValue, $value) signature used by findBy / findAllBy.
     * When $operatorOrValue is a known operator string, returns [$operatorOrValue, $value].
     * Otherwise, treats it as the value itself and returns ['=', $operatorOrValue].
     *
     * @return array{string, mixed}
     */
    private function normalizeOperator(mixed $operatorOrValue, mixed $value): array
    {
        $operators = ['=', '!=', '>', '<', '>=', '<=', 'like', 'in', 'not_in', 'null', 'not_null', 'between'];

        if (is_string($operatorOrValue) && in_array($operatorOrValue, $operators, true)) {
            return [$operatorOrValue, $value];
        }

        return ['=', $operatorOrValue];
    }
}
