---
title: API Reference
weight: 120
---

# API Reference

## AttributeManager

`Jurager\Eav\AttributeManager`

Accessed via `$model->attributes()`. One instance per model instance (cached).

### Static

- `for(string|Attributable $entity): static` — create a manager for an entity instance, class, or morph-map key
- `syncBatch(Collection $batch, int $chunkSize = 500): void` — persist attribute values for multiple entities in chunked batches

### Schema

- `loadSchema(): void` — load all attribute schemas into memory (safe to call multiple times)
- `loadFields(array $codes): void` — batch-load specific fields by code on demand

### Reading

- `field(string $code): ?Field` — return a hydrated Field by attribute code (loads on demand)
- `fields(): array` — return all currently loaded Field objects keyed by code
- `value(string $code, ?int $localeId = null): mixed` — return the typed value for an attribute
- `values(?array $codes = null): Collection` — return entity_attribute records with resolved `value` property
- `indexData(): array` — searchable values for all `searchable: true` attributes (memoized)
- `distinctValues(string $code): Collection` — distinct stored values for a given attribute across all entities
- `aggregate(string $code, string $aggregate): ?float` — SQL aggregate (`sum`, `avg`, `min`, `max`) over a numeric attribute
- `valueMapper(): \Closure` — closure that sets `$record->value`; pass to paginator `->through()`

### Writing

- `set(string $code, mixed $value, ?int $localeId = null): static` — set value in memory (chainable)
- `save(string $code): void` — persist a single attribute value
- `attach(array $fields): bool` — persist fields, leaving other existing rows untouched
- `sync(array $fields): bool` — full replace: persist fields and delete all other existing rows
- `detach(array $ids): void` — delete rows for given attribute IDs
- `fill(array $data): Collection` — fill fields from raw data reusing cached schema (no DB queries after warm-up)

### Querying

- `valuesQuery(?array $codes = null): Builder` — eager-loaded Builder for entity_attribute records
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

Relation:

- `attributeRelation(): MorphToMany` — raw relation to `attributes` through `entity_attribute` pivot

Built-in Scout integration:

- `toSearchableArray(): array` — delegates to `attributes()->indexData()`
- `shouldBeSearchable(): bool` — returns `true` when `indexData()` is non-empty

---

## Attributable Contract

`Jurager\Eav\Contracts\Attributable`

- `getAttributeEntityType(): string` — entity type string (e.g. `'product'`)
- `getDefaultParameters(): array` — default scope parameters passed to `getAvailableAttributesQuery()`
- `getAvailableAttributesQuery(array $params = []): ?Builder` — Builder returning available attribute definitions

---

## EavModels

`Jurager\Eav\EavModels`

Static resolver for config-mapped model classes:

- `EavModels::class('attribute')` — returns the FQCN from config
- `EavModels::query('attribute')` — returns `Model::query()` for the mapped class
- `EavModels::make('attribute')` — returns a new instance of the mapped class
- `EavModels::has('attribute')` — returns `bool` whether the key is mapped

---

## AttributeFieldRegistry

`Jurager\Eav\AttributeFieldRegistry`

Registered as singleton. Maps type codes to `Field` classes.

- `register(string $type, string $class): void` — register a custom field type
- `has(string $type): bool` — check if type is registered
- `resolve(string $type): string` — get the field class name by type code
- `make(Attribute $attribute): Field` — create a field instance from an attribute model
- `all(): array` — return all registered type mappings

---

## AttributeLocaleRegistry

`Jurager\Eav\AttributeLocaleRegistry`

Registered as singleton. Caches locale data per request.

- `defaultLocaleId(): int` — ID for the `app.locale` config value
- `validLocaleIds(): array` — all registered locale IDs
- `isValidLocaleId(int $localeId): bool`
- `localeCodes(): array` — all locale codes keyed by locale ID
- `localeCode(int $localeId): ?string` — code by ID
- `localeId(string $code): ?int` — ID by code
- `resolveLocaleId(?string $code = null): int` — ID by code, or default if not found
- `reset(): void` — clear cache (useful in tests)

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
- `toStorage(): array` — format current values for persistence
- `fromRecord(object $record): mixed` — read raw value from a DB record via `column()`

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

## InteractsWithStorage Trait

`Jurager\Eav\Fields\Concerns\InteractsWithStorage`

Used by `FileField` and `ImageField`.

- `url(string $disk = 'public', ?int $localeId = null): string|array|null` — public URL(s)
- `firstUrl(string $disk = 'public', ?int $localeId = null): ?string` — first URL from a multiple-file field
- `exists(string $disk = 'public', ?int $localeId = null): bool` — check file existence in storage
