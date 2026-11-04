<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Eav\Attributes\Persistence\AttributePersister;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Exceptions\UnknownAttributeException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\IndexDocument;

/**
 * Read and write an entity's attribute values.
 */
class AttributeManager
{
    /** @var array<string, Field> Fields holding the entity's values, by attribute code. */
    private array $fields = [];

    /**
     * Whether every field of the schema has been loaded.
     */
    private bool $complete = false;

    /** @var array<string, true> Codes set in memory since they were last saved. */
    private array $dirty = [];

    /** @var array<string, mixed>|null */
    private ?array $indexData = null;

    public function __construct(
        private readonly Attributable $entity,
        private readonly EntitySchema $schema,
        private readonly EntityValues $values,
        private readonly AttributePersister $persister,
        private readonly LocaleRegistry $locales,
        private readonly IndexDocument $document,
    ) {}

    public function entity(): Attributable
    {
        return $this->entity;
    }

    /**
     * Get the attribute schema for the entity.
     */
    public function schema(): EntitySchema
    {
        return $this->schema;
    }

    /**
     * Load every field of the schema not loaded yet.
     */
    public function ensureSchema(): static
    {
        if (! $this->complete) {
            $this->load($this->schema->attributes()->reject(fn ($attribute) => isset($this->fields[$attribute->code])));
            $this->complete = true;
        }

        return $this;
    }

    /**
     * Load the fields of the given codes not loaded yet.
     *
     * @param  list<string>  $codes
     */
    public function ensureFields(array $codes): static
    {
        $missing = array_values(array_diff($codes, array_keys($this->fields)));

        if ($missing !== [] && ! $this->complete) {
            $this->load($this->schema->only($missing));
        }

        return $this;
    }

    /**
     * Get the loaded fields keyed by attribute code.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Get a field by code, or null if it is unavailable.
     */
    public function field(string $code): ?Field
    {
        return $this->ensureFields([$code])->fields[$code] ?? null;
    }

    /**
     * Get an attribute value in the given or current locale.
     */
    public function value(string $code, ?int $localeId = null): mixed
    {
        $field = $this->field($code);

        if ($field === null) {
            return null;
        }

        if ($localeId === null && $field->isLocalizable()) {
            $localeId = $this->locales->current();
        }

        return $field->value($localeId);
    }

    /**
     * Set an attribute value in memory; null removes the value on save.
     *
     * @throws UnknownAttributeException
     */
    public function set(string $code, mixed $value, ?int $localeId = null): static
    {
        $field = $this->fieldOrFail($code);

        $field->set($value, $this->localeFor($field, $localeId, $value));
        $this->dirty[$code] = true;

        return $this;
    }

    /**
     * Append to a multi-value attribute in memory, keeping its current values.
     *
     * @throws UnknownAttributeException
     */
    public function add(string $code, mixed $value, ?int $localeId = null): static
    {
        $field = $this->fieldOrFail($code);

        $field->add($value, $this->localeFor($field, $localeId));
        $this->dirty[$code] = true;

        return $this;
    }

    /**
     * Determine if any attribute, or the given attribute, has unsaved changes.
     */
    public function isDirty(?string $code = null): bool
    {
        return $code === null ? $this->dirty !== [] : isset($this->dirty[$code]);
    }

    /**
     * Get the fields with unsaved changes.
     *
     * @return array<string, Field>
     */
    public function getDirty(): array
    {
        return array_intersect_key($this->fields, $this->dirty);
    }

    /**
     * Resolve the locale for a value or translation payload.
     */
    private function localeFor(?Field $field, ?int $localeId, mixed $value = null): ?int
    {
        if ($localeId !== null || $field === null || ! $field->isLocalizable() || $field->isTranslationList($value)) {
            return $localeId;
        }

        return $this->locales->current();
    }

    /**
     * Save dirty attributes, removing values set to null.
     *
     * @throws UnknownAttributeException
     */
    public function save(?string $code = null): void
    {
        $fields = $code !== null ? [$code => $this->fieldOrFail($code)] : $this->getDirty();

        if ($fields === []) {
            return;
        }

        $filled = array_filter($fields, static fn (Field $field): bool => $field->isFilled());
        $emptied = array_diff_key($fields, $filled);

        $this->persister->persist(collect($filled));

        if ($emptied !== []) {
            $this->persister->detach(array_values(array_map(static fn (Field $field): int => (int) $field->attribute()->getKey(), $emptied)));
        }

        $this->dirty = array_diff_key($this->dirty, $fields);
        $this->indexData = null;
    }

    /**
     * Persist the given fields, keeping every other stored value.
     *
     * @param  array<string, Field>  $fields
     */
    public function attach(array $fields): void
    {
        $this->fields = [...$this->fields, ...$fields];
        $this->dirty = array_diff_key($this->dirty, $fields);
        $this->indexData = null;

        $this->persister->persist($this->filled($fields));
    }

    /**
     * Persist the given fields and delete every other stored value.
     *
     * @param  array<string, Field>  $fields
     */
    public function replace(array $fields): void
    {
        $this->fields = $fields;
        $this->complete = false;
        $this->dirty = [];
        $this->indexData = null;

        $this->persister->replace($this->filled($fields));
    }

    /**
     * Delete the stored values of the given attributes.
     *
     * @param  list<int>  $attributeIds
     */
    public function detach(array $attributeIds): void
    {
        $this->indexData = null;

        $this->persister->detach($attributeIds);
    }

    /**
     * Build filled fields from raw data, without touching the entity's own fields.
     *
     * @param  array<string, mixed>  $data
     * @param  (callable(string): void)|null  $onRejected  Receives codes the other side of a parent/variant pair holds.
     * @return Collection<string, Field>
     */
    public function fill(array $data, ?callable $onRejected = null): Collection
    {
        return $this->schema->fill($data, $this->entity, $onRejected);
    }

    /**
     * Get stored rows with their resolved field values.
     *
     * @param  list<string>|null  $codes
     * @param  (Closure(Builder): mixed)|null  $query  Narrows the query, e.g. `fn ($query) => $query->filter($request->input('filter'))`.
     */
    public function values(?array $codes = null, ?int $paginated = null, ?Closure $query = null): Collection|LengthAwarePaginator
    {
        return $this->values->list($codes, $paginated, $query);
    }

    /**
     * Get the attribute data for the search index.
     */
    public function indexData(): array
    {
        return $this->indexData ??= $this->document->build($this->entity, $this->values);
    }

    /** @throws UnknownAttributeException */
    private function fieldOrFail(string $code): Field
    {
        return $this->field($code) ?? throw UnknownAttributeException::code($code, $this->entity->getEntityType());
    }

    /**
     * Load fields for the given definitions with the entity's stored values.
     */
    private function load(Collection $attributes): void
    {
        if ($attributes->isEmpty()) {
            return;
        }

        $rows = $this->values->of($attributes->pluck('id')->all())->groupBy('attribute_id');

        foreach ($attributes as $attribute) {
            $field = $this->schema->make($attribute, $this->entity);
            $field->hydrate($rows->get($attribute->id, collect()));

            $this->fields[$attribute->code] = $field;
        }
    }

    /**
     * @param  array<string, Field>  $fields
     * @return Collection<string, Field>
     */
    private function filled(array $fields): Collection
    {
        return collect($fields)->filter(static fn (Field $field): bool => $field->isFilled());
    }
}
