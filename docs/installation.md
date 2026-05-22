---
title: Installation
weight: 10
---

## Installing the Package

You may install the package via Composer:

```bash
composer require jurager/eav
```

## Publishing the Configuration

Publish the configuration file to your application:

```bash
php artisan vendor:publish --tag=eav-config
```

This creates `config/eav.php` with two sections: `models` (override any package model with a subclass) and `field_types` (register custom field types). See [Overriding Models](#overriding-models) and [Field Types](field-types.md) for details.

## Running Migrations

Publish the package migrations and run them:

```bash
php artisan vendor:publish --tag=eav-migrations
php artisan migrate
```

The package adds the following tables to your database:

| Table | Purpose |
|---|---|
| `locales` | Supported locale codes (`en`, `ru`, …) |
| `attribute_types` | Field type definitions (`text`, `number`, `select`, …) |
| `attribute_groups` | Display grouping for attributes |
| `attributes` | Attribute schema per entity type |
| `attribute_enums` | Allowed options for `select`-type attributes |
| `entity_attribute` | Polymorphic typed values per entity instance |
| `entity_translations` | Localized labels for any model |

To add application-specific columns to the `attributes` table — for example, a `measurement_id` foreign key — you should create a separate migration in your application after publishing the package migrations. This keeps your customizations clear of the package's upgrade path.

## Overriding Models

Every model inside the package is resolved via the `eav.models` config key. To extend a model, you may create a subclass and point the config at it:

```php
// app/Models/Attribute.php
class Attribute extends \Jurager\Eav\Models\Attribute
{
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }
}
```

```php
// config/eav.php
'models' => [
    'attribute' => \App\Models\Attribute::class,
],
```

All seven model keys may be overridden independently:

| Key | Default class |
|---|---|
| `attribute` | `Jurager\Eav\Models\Attribute` |
| `attribute_type` | `Jurager\Eav\Models\AttributeType` |
| `attribute_group` | `Jurager\Eav\Models\AttributeGroup` |
| `attribute_enum` | `Jurager\Eav\Models\AttributeEnum` |
| `entity_attribute` | `Jurager\Eav\Models\EntityAttribute` |
| `entity_translation` | `Jurager\Eav\Models\EntityTranslation` |
| `locale` | `Jurager\Eav\Models\Locale` |

Relations and observers defined in the base models remain active in subclasses — you only need to override what you intend to extend.
