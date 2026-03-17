---
title: API Reference
weight: 120
---

# API Reference

## AttributeManager

`Jurager\Eav\AttributeManager`

Accessed via `$model->attributes()`. One instance per model instance (cached).

- `get(string $code, ?int $localeId = null): mixed` — get attribute value
- `set(string $code, mixed $value, ?int $localeId = null): void` — set attribute value
- `fill(array $data): bool` — fill multiple attributes, returns false on validation error
- `save(): void` — persist all pending changes to `entity_attribute`
- `errors(): array` — validation errors per attribute code after a failed `fill()`
- `getIndexData(): array` — searchable values for all `searchable: true` attributes
- `buildSubquery(string $code, mixed $value, string $operator): ?Builder` — subquery used by Eloquent scopes

## HasAttributes Trait

`Jurager\Eav\Concerns\HasAttributes`

Query scopes added to the model:

- `scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '=')`
- `scopeWhereAttributeLike(Builder $query, string $code, string $value)`
- `scopeWhereAttributeBetween(Builder $query, string $code, float|int $min, float|int $max)`
- `scopeWhereAttributeIn(Builder $query, string $code, array $values)`
- `scopeWhereAttributes(Builder $query, array $conditions)`

Relation:

- `attributeRelation(): MorphToMany` — raw relation to `attributes` through `entity_attribute` pivot

## Attributable Contract

`Jurager\Eav\Contracts\Attributable`

- `getAttributeEntityType(): string` — entity type string (e.g. `'product'`)
- `getDefaultParameters(): array` — default scope parameters passed to `getAvailableAttributesQuery()`
- `getAvailableAttributesQuery(array $params = []): ?Builder` — builder returning available attribute definitions

## EavModels

`Jurager\Eav\EavModels`

Static resolver for config-mapped model classes:

- `EavModels::class('attribute')` — returns the FQCN from config
- `EavModels::query('attribute')` — returns `Model::query()` for the mapped class
- `EavModels::make('attribute')` — returns a new instance of the mapped class
- `EavModels::has('attribute')` — returns `bool` whether the key is mapped

## AttributeFieldRegistry

`Jurager\Eav\AttributeFieldRegistry`

Registered as singleton. Maps type codes to `Field` classes.

- `register(string $type, string $class): void` — register a custom field type
- `has(string $type): bool` — check if type is registered
- `get(string $type): string` — get field class by type code
- `make(Attribute $attribute): Field` — create field instance from attribute model
- `all(): array` — return all registered types

## AttributeLocaleRegistry

`Jurager\Eav\AttributeLocaleRegistry`

Registered as singleton. Caches locale data per request.

- `getDefaultLocaleId(): int`
- `getValidLocaleIds(): array`
- `isValidLocaleId(int $localeId): bool`
- `getLocaleCodes(): array`
- `getLocaleCode(int $localeId): ?string`
- `getLocaleId(string $code): ?int`
- `resolveLocaleId(?string $code = null): int`
- `reset(): void`

## AttributeInheritanceResolver

`Jurager\Eav\AttributeInheritanceResolver`

Registered as singleton.

- `resolve(Collection $entities, string $model): Collection` — expand entities with their inheriting ancestors

## Field (abstract)

`Jurager\Eav\Fields\Field`

Base class for all field type implementations.

- `getStorageColumn(): string` *(abstract)* — typed column name
- `validateValue(mixed $value): bool` *(abstract)* — type-specific validation
- `processValue(mixed $value): mixed` *(abstract)* — normalize before storing
- `fill(mixed $values): bool` — validate and normalize incoming payload
- `hydrate(Collection $records): void` — load values from DB records
- `toStorage(): array` — format values for persistence
- `getValue(?int $localeId = null): mixed`
- `setValue(mixed $value, ?int $localeId = null): void`
- `removeValue(?int $localeId = null): void`
- `hasValue(?int $localeId = null): bool`
- `isFilled(): bool`
- `hasErrors(): bool`
- `getErrors(): array`
- `getIndexData(): array`
- `toMetadata(): array`
- `isLocalizable(): bool`
- `isMultiple(): bool`
- `isMandatory(): bool`
- `isUnique(): bool`
- `isFilterable(): bool`
- `isSearchable(): bool`

Storage column constants: `STORAGE_TEXT`, `STORAGE_INTEGER`, `STORAGE_FLOAT`, `STORAGE_BOOLEAN`, `STORAGE_DATE`, `STORAGE_DATETIME`.
