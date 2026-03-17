<?php

namespace Jurager\Eav;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
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
     * @var array<string, Collection>
     */
    protected array $cachedAttributes = [];

    protected AttributeFieldRegistry $fieldRegistry;

    private ?AttributePersister $persister = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $indexData = null;

    /**
     * @param  Attributable|null  $entity  Concrete entity instance, or null for schema-only mode.
     * @param  Collection<int, mixed>|null  $preloadedAttributes  Pre-fetched attributes to skip the initial DB query.
     */
    public function __construct(
        protected ?Attributable $entity = null,
        ?Collection $preloadedAttributes = null
    ) {
        $this->fieldRegistry = app(AttributeFieldRegistry::class);

        if ($preloadedAttributes !== null) {
            $this->cachedAttributes['default'] = $preloadedAttributes;
        }
    }

    /**
     * Create a manager for a concrete entity instance, class, or string entity type.
     *
     *
     * @throws InvalidArgumentException If a class string is passed that does not implement Attributable.
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

            return new static(new $entity);
        }

        // String entity type (e.g. 'product') — load schema without an instance.
        return new static(null, EavModels::query('attribute')
            ->forEntity($entity)
            ->withRelations()
            ->get());
    }

    /**
     * Load all attribute schemas (without values) into $this->fields.
     *
     * @throws BindingResolutionException|JsonException
     */
    public function loadSchema(): void
    {
        $params = $this->entity?->getDefaultParameters() ?? [];

        $this->getAttributes($params)
            ->reject(fn ($attr) => isset($this->fields[$attr->code]))
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->fieldRegistry->make($attr));
    }

    /**
     * Batch-load and hydrate specific fields by attribute code.
     *
     * @param  array<string>  $codes
     *
     * @throws JsonException|BindingResolutionException
     */
    public function loadFields(array $codes): void
    {
        $codes = array_diff($codes, array_keys($this->fields));

        if (empty($codes)) {
            return;
        }

        $attributes = $this->getAttributes()->whereIn('code', $codes);

        if ($attributes->isEmpty()) {
            return;
        }

        $this->hydrateAttributes($attributes);
    }

    /**
     * Return all currently loaded Field objects.
     *
     * @return array<string, Field>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Get a single hydrated Field by attribute code.
     *
     *
     * @throws JsonException|BindingResolutionException
     */
    public function getField(string $code): ?Field
    {
        if (isset($this->fields[$code])) {
            return $this->fields[$code];
        }

        $attribute = $this->getAttributes()->firstWhere('code', $code);

        if (! $attribute) {
            return null;
        }

        $this->hydrateAttributes(collect([$attribute]));

        return $this->fields[$code];
    }

    /**
     * Get a single attribute value for the entity.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function get(string $code, ?int $localeId = null): mixed
    {
        return $this->getField($code)?->getValue($localeId);
    }

    /**
     * Return attached entity_attribute records with their resolved typed values.
     *
     * @param  array<string>|null  $codes
     * @return Collection<int, Model>
     *
     * @throws BindingResolutionException
     */
    public function values(?array $codes = null): Collection
    {
        return $this->valuesQuery($codes)->get()->map($this->hydrator());
    }

    /**
     * Return index data for all searchable attributes.
     *
     * @return array<string, mixed>
     */
    public function getIndexData(): array
    {
        return $this->indexData ??= $this->buildIndexData();
    }

    /**
     * Return a Builder on the entity model scoped to entities whose attribute
     * with the given code matches the given value using the given operator.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function attributeQuery(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->getAttributes()->firstWhere('code', $code)?->entity_type;

        $modelClass = $entityType ? Relation::getMorphedModel($entityType) : null;
        $sub = $this->buildSubquery($code, $value, $operator, $localeId);

        if (! $sub || ! $modelClass) {
            return null;
        }

        return $modelClass::query()->whereIn('id', $sub);
    }

    /**
     * Build a raw entity_attribute subquery for use in scopes or attributeQuery().
     *
     * @throws JsonException|BindingResolutionException
     */
    public function buildSubquery(string $code, mixed $value = null, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $field = $this->getField($code);
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->getAttributes()->firstWhere('code', $code)?->entity_type;

        if (! $field || ! $entityType) {
            return null;
        }

        $sub = EavModels::query('entity_attribute')
            ->select('entity_id')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->getAttribute()->id);

        if ($field->isLocalizable()) {
            $sub->whereHas('translations', function ($q) use ($value, $operator, $localeId) {
                $col = 'entity_translations.label';
                match ($operator) {
                    'like' => $q->where($col, 'LIKE', "%{$value}%"),
                    'in' => $q->whereIn($col, (array) $value),
                    'not_in' => $q->whereNotIn($col, (array) $value),
                    'null' => $q->whereNull($col),
                    'not_null' => $q->whereNotNull($col),
                    default => $q->where($col, $operator, $value),
                };
                if ($localeId) {
                    $q->where('entity_translations.locale_id', $localeId);
                }
            });
        } else {
            $col = $field->getStorageColumn();
            match ($operator) {
                'like' => $sub->where($col, 'LIKE', "%{$value}%"),
                'in' => $sub->whereIn($col, (array) $value),
                'not_in' => $sub->whereNotIn($col, (array) $value),
                'null' => $sub->whereNull($col),
                'not_null' => $sub->whereNotNull($col),
                'between' => $sub->whereBetween($col, $value),
                default => $sub->where($col, $operator, $value),
            };
        }

        return $sub;
    }

    /**
     * Build an eager-loaded Builder for entity_attribute records of the current entity.
     *
     * @param  array<string>|null  $codes
     */
    public function valuesQuery(?array $codes = null): Builder
    {
        return $this->entityQuery()
            ->when($codes, fn ($q) => $q->whereHas('attribute', fn ($q) => $q->whereIn('code', $codes)))
            ->with([
                'attribute.type',
                'attribute.group.translations',
                'attribute.measurement.translations',
                'attribute.measurement.units',
                'attribute.translations',
                'attribute.enums.translations',
                'translations',
            ]);
    }

    /**
     * Return distinct stored values for a given attribute across all entities of this type.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function distinctValues(string $code): Collection
    {
        $field = $this->getField($code);
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->getAttributes()->firstWhere('code', $code)?->entity_type;

        if (! $field || ! $entityType) {
            return collect();
        }

        return EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->getAttribute()->id)
            ->whereNotNull($field->getStorageColumn())
            ->distinct()
            ->pluck($field->getStorageColumn());
    }

    /**
     * Aggregate numeric attribute values across all entities of this type.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function aggregate(string $code, string $aggregate): ?float
    {
        $field = $this->getField($code);
        $entityType = $this->entity?->getAttributeEntityType()
            ?? $this->getAttributes()->firstWhere('code', $code)?->entity_type;

        if (! $field || ! $entityType) {
            return null;
        }

        $col = $field->getStorageColumn();

        if (! in_array($col, [Field::STORAGE_FLOAT, Field::STORAGE_INTEGER], true)) {
            return null;
        }

        if (! in_array($aggregate, ['sum', 'avg', 'min', 'max'], true)) {
            throw new InvalidArgumentException("Invalid aggregate '$aggregate'. Allowed: sum, avg, min, max.");
        }

        $result = EavModels::query('entity_attribute')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->getAttribute()->id)
            ->whereNotNull($col)
            ->{$aggregate}($col);

        return $result !== null ? (float) $result : null;
    }

    /**
     * Return a closure that resolves and assigns the typed value on an entity_attribute record.
     */
    public function hydrator(): \Closure
    {
        return function ($record) {
            $record->value = $this->fieldRegistry->make($record->attribute)->getValueFromRecord($record);

            return $record;
        };
    }

    /**
     * Find a single entity whose attribute with the given code equals the given value.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function findBy(string $code, mixed $operatorOrValue, mixed $value = null, ?int $localeId = null): ?Model
    {
        [$operator, $value] = $this->parseOperatorAndValue($operatorOrValue, $value);

        return $this->attributeQuery($code, $value, $operator, $localeId)?->first();
    }

    /**
     * Find all entities whose attribute with the given code matches the given value.
     *
     * @return Collection<int, Model>
     *
     * @throws JsonException|BindingResolutionException
     */
    public function findAllBy(string $code, mixed $operatorOrValue, mixed $value = null, ?int $localeId = null): Collection
    {
        [$operator, $value] = $this->parseOperatorAndValue($operatorOrValue, $value);

        return $this->attributeQuery($code, $value, $operator, $localeId)?->get() ?? collect();
    }

    /**
     * @return array{string, mixed}
     */
    private function parseOperatorAndValue(mixed $operatorOrValue, mixed $value): array
    {
        $operators = ['=', '!=', '>', '<', '>=', '<=', 'like', 'in', 'not_in', 'null', 'not_null', 'between'];

        if (is_string($operatorOrValue) && in_array($operatorOrValue, $operators, true)) {
            return [$operatorOrValue, $value];
        }

        return ['=', $operatorOrValue];
    }

    /**
     * Set an attribute value in memory (does not persist to the database).
     *
     * @throws JsonException|BindingResolutionException
     */
    public function set(string $code, mixed $value, ?int $localeId = null): static
    {
        $this->getField($code)?->setValue($value, $localeId);

        return $this;
    }

    /**
     * Persist a single attribute value to the database.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function save(string $code): void
    {
        $field = $this->getField($code);

        if (! $field?->isFilled()) {
            return;
        }

        $this->persister()->saveField($field);
    }

    /**
     * Persist the given fields, merging with any existing ones (no deletions).
     *
     * @param  array<string, Field>  $fields
     */
    public function attach(array $fields): bool
    {
        $this->fields = array_merge($this->fields, $fields);

        $this->persister()->persist(collect($this->fields)->filter(fn (Field $f) => $f->isFilled()));

        return true;
    }

    /**
     * Persist the given fields and remove any existing rows not in this set.
     *
     * @param  array<string, Field>  $fields
     */
    public function sync(array $fields): bool
    {
        $this->fields = $fields;

        $filled = collect($this->fields)->filter(fn (Field $f) => $f->isFilled());
        $filledIds = $filled->map(fn (Field $f) => $f->getAttribute()->id)->values()->all();

        $persister = $this->persister();
        $persister->deleteExcluding($filledIds);
        $persister->persist($filled);

        return true;
    }

    /**
     * Remove entity_attribute rows for the given attribute IDs.
     *
     * @param  array<int>  $ids
     */
    public function detach(array $ids): void
    {
        $this->persister()->detachByAttributeIds($ids);
    }

    /**
     * Return the raw Builder for available attributes.
     *
     * @param  array<string, mixed>  $params
     */
    public function availableAttributesQuery(array $params = []): ?Builder
    {
        return $this->getEntityOrFail()->getAvailableAttributesQuery($params);
    }

    /**
     * Return the current entity or throw if the manager was built without one.
     *
     * @throws LogicException
     */
    protected function getEntityOrFail(): Attributable
    {
        return $this->entity ?? throw new LogicException('Entity is required. Use AttributeManager::for($entity).');
    }

    protected function persister(): AttributePersister
    {
        return $this->persister ??= new AttributePersister($this->getEntityOrFail());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     *
     * @throws JsonException
     */
    protected function getAttributes(array $params = []): Collection
    {
        $key = empty($params) ? 'default' : md5(json_encode($params, JSON_THROW_ON_ERROR));

        return $this->cachedAttributes[$key] ??= $this->availableAttributesQuery($params)?->get() ?? collect();
    }

    /**
     * @param  Collection<int, mixed>  $attributes
     *
     * @throws BindingResolutionException
     */
    protected function hydrateAttributes(Collection $attributes): void
    {
        $records = $this->entity
            ? $this->entityQuery()
                ->whereIn('attribute_id', $attributes->pluck('id')->all())
                ->get()
                ->groupBy('attribute_id')
            : collect();

        foreach ($attributes as $attribute) {
            $field = $this->fieldRegistry->make($attribute);
            $field->hydrate($records->get($attribute->id, collect()));
            $this->fields[$attribute->code] = $field;
        }
    }

    protected function entityQuery(): Builder
    {
        $entity = $this->getEntityOrFail();

        return EavModels::query('entity_attribute')
            ->where('entity_type', $entity->getAttributeEntityType())
            ->where('entity_id', $entity->id);
    }

    protected function indexQuery(): Builder
    {
        return $this->entityQuery()
            ->whereHas('attribute')
            ->with([
                'attribute',
                'attribute.enums.translations',
                'translations',
            ]);
    }

    /**
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

                return $field->isSearchable() ? $field->getIndexData() : [];
            });

        return $attributes->isNotEmpty() ? ['attributes' => $attributes->all()] : [];
    }
}
