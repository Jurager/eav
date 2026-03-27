# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`jurager/eav` is a Laravel package providing a flexible Entity-Attribute-Value (EAV) system. It supports typed attribute storage, per-locale values, attribute inheritance, and a pluggable field type registry. Requires PHP ^8.4 and Laravel 11–13.

## Commands

```bash
# Install dependencies
composer install

# Run tests (orchestra/testbench is the test harness — no phpunit.xml exists yet)
vendor/bin/phpunit

# Publish config to consuming app
php artisan vendor:publish --tag=eav-config

# Publish migrations to consuming app
php artisan vendor:publish --tag=eav-migrations
```

There are currently no tests in the `tests/` directory.

## Architecture

### Service Provider

`EavServiceProvider` registers three singletons: `FieldTypeRegistry`, `LocaleRegistry`, and `AttributeInheritanceResolver`. It also loads migrations and wires the `AttributeObserver` to the configured `Attribute` model.

### Making a Model Attributable

A model must implement `Contracts/Attributable` and use the `Concerns/HasAttributes` trait. `getAttributeEntityType()` returns the morph-map key (e.g. `'product'`) used to scope attribute schemas.

### AttributeManager (`src/Support/AttributeManager.php`)

The central orchestrator. Three factory modes:
- `AttributeManager::for($model)` — entity instance: loads values + schema
- `AttributeManager::for(Product::class)` — FQCN: schema only
- `AttributeManager::for('product')` — morph-map key: schema only

Schema is cached in a static process-level registry (`$schemaRegistry`). Batch persistence is done via `AttributeManager::sync(Collection $entities)`.

### Field System (`src/Fields/`)

Abstract base `Field` defines three contracts: `column()` (returns one of the six `STORAGE_*` constants), `validate(mixed $value): bool`, and `normalize(mixed $value): mixed`. Nine built-in types: `TextField`, `TextAreaField`, `NumberField`, `DateField`, `BooleanField`, `SelectField`, `ImageField`, `FileField`, `LinkField`.

Custom fields must extend `Field` and be registered in `config/eav.php` under `field_types`.

### Persistence (`src/Support/AttributePersister.php`)

Writes to two tables: `entity_attribute` (typed value columns) and `entity_translations` (per-locale labels). Chunks inserts at 500 rows to stay within PDO bind parameter limits.

### Database Schema

Seven migrations create: `locales`, `attribute_types`, `attribute_groups`, `attributes` (schema definitions with flags: `mandatory`, `localizable`, `multiple`, `unique`, `filterable`, `searchable`), `entity_translations`, `entity_attribute` (six typed columns: `value_text`, `value_integer`, `value_float`, `value_boolean`, `value_date`, `value_datetime`), and `attribute_enums`.

### Query Scopes (`Concerns/HasAttributes`)

Available on attributable models: `whereAttribute()`, `whereAttributeLike()`, `whereAttributeBetween()`, `whereAttributeIn()`, `whereAttributes()`. These join against `entity_attribute` filtered by the entity's morph type.

### Registries

- `FieldTypeRegistry` — maps type-code strings to `Field` class names; populated from `config/eav.php`
- `LocaleRegistry` — manages available locales; populated from the `locales` table

### Configuration (`config/eav.php`)

Two sections:
- `models` — override any of the six Eloquent models with a custom subclass
- `field_types` — map type-code strings to `Field` implementations
