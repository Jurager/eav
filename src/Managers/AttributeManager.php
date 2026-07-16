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
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\MissingEntityException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Support\AttributePersister;
use Jurager\Eav\Support\AttributeQueryBuilder;
use Jurager\Eav\Support\BatchAttributePersister;

class AttributeManager
{
    /** @var array<string, Field> */
    protected array $fields = [];

    private bool $schemaLoaded = false;

    private ?AttributePersister $persister = null;

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

    private ?FieldFactory $fieldFactory = null;

    private ?EnumRegistry $enumRegistry = null;

    private ?AttributeQueryBuilder $builder = null;

    /** FQCN stored for schema-only managers created from a class string. */
    protected ?string $entityClass = null;

    public function __construct(
        protected ?Attributable $entity = null,
    ) {
    }

    /** Create a manager for an entity instance, FQCN, or morph-map key. */
    public static function for(string|Attributable $entity): static
    {
        if ($entity instanceof Attributable) {
            return new static($entity);
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
     * @param Collection<int, array{entity: Attributable, data: array<string, mixed>}> $batch
     */
    public static function sync(Collection $batch, ?self $prebuiltSchema = null, int $chunkSize = 500, ?callable $onError = null): void
    {
        if ($batch->isEmpty()) {
            return;
        }

        foreach ($batch->chunk(max(1, $chunkSize)) as $chunk) {
            $persister = new BatchAttributePersister();

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

    /** Ensure the schema is loaded. */
    public function ensureSchema(): static
    {
        if ($this->schemaLoaded) {
            return $this;
        }

        ($this->query($this->entity?->getEavScopes() ?? [])?->get() ?? collect())
            ->reject(fn ($attr) => isset($this->fields[$attr->code]))
            ->each(fn ($attr) => $this->fields[$attr->code] = $this->makeField($attr));

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

        $attributes = $this->query($this->entity?->getEavScopes() ?? [])?->whereIn('code', $codes)->get() ?? collect();

        if ($attributes->isNotEmpty()) {
            $this->hydrate($attributes);
        }
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

        $this->persister()->persist(collect($fields)->filter(fn (Field $f) => $f->isFilled()));
    }

    /** Replace all entity_attribute rows with the given fields. */
    public function replace(array $fields): void
    {
        $this->fields = $fields;
        $this->persister()->replace(collect($this->fields)->filter(fn (Field $f) => $f->isFilled()));
    }

    /** Delete entity_attribute rows for the given attribute IDs. */
    public function detach(array $ids): void
    {
        $this->persister()->detach($ids);
    }

    /** Fill fields from raw data. */
    public function fill(array $data): Collection
    {
        $this->ensureSchema();

        return collect($data)
            ->filter(fn ($_, $code) => isset($this->fields[$code]))
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

        if ($this->entity instanceof Model && $this->entity->relationLoaded('attribute_values')) {
            $collection = $this->entity->attribute_values;

            if ($codes !== null) {
                $collection = $collection->filter(
                    fn ($ea) => $ea->relationLoaded('attribute') && in_array($ea->attribute->code ?? null, $codes, true)
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
            $this->enumRegistry(),
            fn (string $code) => $this->field($code),
            fn (string $code) => $this->entity?->getEavEntityType()
                ?? ($this->fields[$code] ?? null)?->attribute()->entity_type,
        );
    }

    protected static function buildFromCollection(Collection $attributes): static
    {
        $attributes->loadMissing('type');
        $instance = new static(null);

        foreach ($attributes as $attribute) {
            $instance->fields[$attribute->code] = $instance->makeField($attribute);
        }

        $instance->schemaLoaded = true;

        return $instance;
    }

    protected static function buildFromAttributable(Attributable $entity, SchemaRegistry $registry): static
    {
        $parameters = $entity->getEavScopes();
        $sorted = $parameters;
        sort($sorted);

        $parametersKey = empty($parameters) ? 'default' : md5(json_encode($sorted, JSON_THROW_ON_ERROR));
        $registryKey = "{$entity->getEavEntityType()}:{$parametersKey}";

        $attributes = $registry->resolve(
            $registryKey,
            fn () => $entity->getAvailableAttributesQuery($parameters)?->get() ?? new EloquentCollection()
        );

        return static::buildFromCollection($attributes);
    }

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
            $field = $this->makeField($attribute);
            $field->hydrate($records->get($attribute->id, collect()));
            $this->fields[$attribute->code] = $field;
        }
    }

    protected function entityQuery(): Builder
    {
        return Eav::$entityAttributeModel::query()
            ->where('entity_type', $this->resolveEntity()->getEavEntityType())
            ->where('entity_id', $this->resolveEntity()->id);
    }

    protected function resolveEntity(): Attributable
    {
        return $this->entity ?? ($this->entityClass ? new ($this->entityClass)() : throw MissingEntityException::forManager());
    }

    private function makeField(Attribute $attribute): Field
    {
        return $this->fieldFactory()->make($attribute)->forEntity($this->entity);
    }

    private function persister(): AttributePersister
    {
        return $this->persister ??= new AttributePersister($this->resolveEntity());
    }

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

    private function fieldFactory(): FieldFactory
    {
        return $this->fieldFactory ??= app(FieldFactory::class);
    }

    private function enumRegistry(): EnumRegistry
    {
        return $this->enumRegistry ??= app(EnumRegistry::class);
    }
}
