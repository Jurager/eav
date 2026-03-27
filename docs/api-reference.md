---
title: API Reference
weight: 120
---

# API Reference

## AttributeManager

`Jurager\Eav\Support\AttributeManager`

Accessed via `$model->attributes()`. One instance per model instance (cached).

### Static

- `for(string|Attributable $entity): static` — create a manager for an entity instance, class, or morph-map key
- `schema(Attributable|Collection $entityOrAttributes): static` — schema-only manager; when given an entity, result is cached by (entity_type, params); when given a Collection of Attribute models, built immediately from those attributes
- `sync(Collection $batch, ?static $prebuiltSchema = null, int $chunkSize = 500, ?callable $onError = null): void` — persist attribute values for multiple entities in chunked batches; each entity is persisted in its own transaction; if `$onError` is provided it receives `(\Throwable $e, Attributable $entity)` on failure and processing continues with the next entity — without it the exception is re-thrown

### Schema

- `ensureSchema(): static` — guarantee all field definitions are built from the full schema
- `ensureFields(array $codes): void` — guarantee specific field definitions are built on demand

### Reading

- `field(string $code): ?Field` — return a hydrated Field by attribute code (loads on demand)
- `fields(): array` — return all currently loaded Field objects keyed by code
- `value(string $code, ?int $localeId = null): mixed` — return the typed value for an attribute
- `values(?array $codes = null, ?int $paginated = null): Collection|LengthAwarePaginator` — entity_attribute records with resolved `value` property; pass `$paginated` to get a paginated result
- `indexData(): array` — searchable values for all `searchable: true` attributes (memoized)
- `distinctValues(string $code): Collection` — distinct stored values for a given attribute across all entities
- `aggregate(string $code, string $aggregate): ?float` — SQL aggregate (`sum`, `avg`, `min`, `max`) over a numeric attribute

### Writing

- `set(string $code, mixed $value, ?int $localeId = null): static` — set value in memory (chainable)
- `save(string $code): void` — persist a single attribute value
- `attach(array $fields): bool` — persist fields, leaving other existing rows untouched
- `replace(array $fields): bool` — full replace: persist fields and delete all other existing rows
- `detach(array $ids): void` — delete rows for given attribute IDs
- `fill(array $data): Collection` — fill fields from raw data reusing cached schema (no DB queries after warm-up)

### Querying

- `subquery(string $code, mixed $value, string $operator, ?int $localeId): ?Builder` — subquery used by Eloquent scopes
- `attributeQuery(string $code, mixed $value, string $operator, ?int $localeId): ?Builder` — Builder scoped to matching entity IDs
- `findBy(string $code, mixed $operatorOrValue, mixed $value, ?int $localeId): ?Model`
- `findAllBy(string $code, mixed $operatorOrValue, mixed $value, ?int $localeId): Collection`
- `attributesQuery(array $params = []): ?Builder` — Builder returning available attribute definitions

---

## HasAttributes Trait

`Jurager\Eav\Concerns\HasAttributes`

Query scopes added to the model:

- `scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '=')`
- `scopeWhereAttributeLike(Builder $query, string $code, string $value)`
- `scopeWhereAttributeBetween(Builder $query, string $code, float|int $min, float|int $max)`
- `scopeWhereAttributeIn(Builder $query, string $code, array $values)`
- `scopeWhereAttributes(Builder $query, array $conditions)`

Validation:

- `validate(array $input): array` — validate and fill attributes; returns `array<string, Field>`; throws `ValidationException` on failure

Relations:

- `attribute_relation(): MorphToMany` — raw relation to `attributes` through `entity_attribute` pivot (with value columns)
- `attribute_values(): HasMany` — raw relation to `entity_attribute` rows for this entity

Default implementations of the `Attributable` contract (override as needed):

- `shouldInheritAttributes(): bool` — returns `false`; override to enable attribute inheritance
- `getDefaultParameters(): array` — returns `[]`; override to pass scope parameters (e.g. category IDs)
- `available_attributes(): ?BelongsToMany` — returns `null`; override on scope-provider models (e.g. Category)

---

## HasSearchableAttributes Trait

`Jurager\Eav\Concerns\HasSearchableAttributes`

Optional Scout integration. Use alongside `Laravel\Scout\Searchable`, resolving the conflict:

```php
use HasAttributes, HasSearchableAttributes, Searchable {
    HasSearchableAttributes::toSearchableArray insteadof Searchable;
    HasSearchableAttributes::shouldBeSearchable insteadof Searchable;
}
```

- `toSearchableArray(): array` — delegates to `attributes()->indexData()`; override to include model-specific fields
- `shouldBeSearchable(): bool` — returns `true` when `indexData()` is non-empty

---

## Attributable Contract

`Jurager\Eav\Contracts\Attributable`

Only `getAttributeEntityType()` must be implemented. All other methods have defaults in `HasAttributes`.

| Method | Required | Default in `HasAttributes` |
|---|---|---|
| `getAttributeEntityType(): string` | ✓ | — |
| `getDefaultParameters(): array` | | `[]` |
| `getAvailableAttributesQuery(array $params = []): ?Builder` | | provided by `HasAttributes` |
| `shouldInheritAttributes(): bool` | | `false` |
| `available_attributes(): ?BelongsToMany` | | `null` |

---

## EavModels

`Jurager\Eav\EavModels`

Static resolver for config-mapped model classes:

- `EavModels::class('attribute')` — returns the FQCN from config
- `EavModels::query('attribute')` — returns `Model::query()` for the mapped class
- `EavModels::make('attribute')` — returns a new instance of the mapped class
- `EavModels::has('attribute')` — returns `bool` whether the key is mapped

---

## FieldTypeRegistry

`Jurager\Eav\Registry\FieldTypeRegistry`

Registered as singleton. Maps attribute type codes to `Field` classes. Pre-populated from `config/eav.php`.

- `register(string $type, string $class): void` — register a custom field type
- `has(string $type): bool` — check if type is registered
- `resolve(string $type): string` — get the field class name by type code
- `make(Attribute $attribute): Field` — create a field instance from an attribute model
- `all(): array` — return all registered type mappings

---

## LocaleRegistry

`Jurager\Eav\Registry\LocaleRegistry`

Registered as singleton. Caches locale data to avoid repeated DB queries.

- `defaultLocaleId(): int` — ID for the `app.locale` config value
- `validLocaleIds(): array` — all registered locale IDs
- `isValidLocaleId(int $localeId): bool`
- `localeCodes(): array` — all locale codes keyed by locale ID
- `localeCode(int $localeId): ?string` — code by ID
- `localeId(string $code): ?int` — ID by code
- `resolveLocaleId(?string $code = null): int` — ID by code, or default if not found
- `flush(): void` — clear cache (useful in tests)

---

## AttributeInheritanceResolver

`Jurager\Eav\AttributeInheritanceResolver`

Registered as singleton.

- `resolve(Collection $entities, string $model): Collection` — expand entities with their inheriting ancestors

---

## Field (abstract)

`Jurager\Eav\Fields\Field`

Base class for all field type implementations.

### Abstract

- `column(): string` — typed storage column name (e.g. `value_text`)
- `validate(mixed $value): bool` — type-specific validation for a single value
- `normalize(mixed $value): mixed` — normalize raw input to the stored type

### Lifecycle

- `fill(mixed $values): bool` — validate and normalize incoming payload; returns `false` on error
- `hydrate(Collection $records): void` — load values from DB records
- `from(object $model): mixed` — read raw value from a model via `column()`
- `toStorage(): array` — format current values for persistence

### Reading / Writing

- `value(?int $localeId = null): mixed` — return the typed value for a locale
- `set(mixed $value, ?int $localeId = null): void` — set value in memory
- `forget(?int $localeId = null): void` — remove value for a locale (or all values)
- `has(?int $localeId = null): bool` — determine if a non-null value exists

### Metadata

- `attribute(): Attribute` — the Attribute definition model
- `code(): string` — attribute code
- `toMetadata(): array` — type, flags as array
- `isFilled(): bool`
- `hasErrors(): bool`
- `errors(): array`
- `indexData(): array` — search-engine-ready key/value pairs
- `isLocalizable(): bool`
- `isMultiple(): bool`
- `isMandatory(): bool`
- `isUnique(): bool`
- `isFilterable(): bool`
- `isSearchable(): bool`

### Storage column constants

```php
Field::STORAGE_TEXT      // value_text
Field::STORAGE_INTEGER   // value_integer
Field::STORAGE_FLOAT     // value_float
Field::STORAGE_BOOLEAN   // value_boolean
Field::STORAGE_DATE      // value_date
Field::STORAGE_DATETIME  // value_datetime
```

---

## SelectField

`Jurager\Eav\Fields\SelectField`

Additional methods on top of `Field`:

- `enum(?int $localeId = null): ?AttributeEnum` — the AttributeEnum model for a single-select value
- `enums(?int $localeId = null): array` — AttributeEnum models for multi-select values
- `label(?int $localeId = null): string|array|null` — translated label(s)

---

## HasFileStorage Trait

`Jurager\Eav\Fields\Concerns\HasFileStorage`

Used by `FileField` and `ImageField`.

- `url(string $disk = 'public', ?int $localeId = null): string|array|null` — public URL(s)
- `firstUrl(string $disk = 'public', ?int $localeId = null): ?string` — first URL from a multiple-file field
- `exists(string $disk = 'public', ?int $localeId = null): bool` — check file existence in storage

