---
title: Installation
weight: 10
---

# Installation

## Composer

```bash
composer require jurager/eav
```

## Configuration

```bash
php artisan vendor:publish --tag=eav-config
```

Creates `config/eav.php` with two sections: `models` (override any package model with a subclass) and `field_types` (register custom field types).

## Database

```bash
php artisan vendor:publish --tag=eav-migrations
php artisan migrate
```

| Table | Purpose |
|---|---|
| `locales` | Supported locale codes (`en`, `ru`, …) |
| `attribute_types` | Field type definitions (`text`, `number`, `select`, …) |
| `attribute_groups` | Display grouping for attributes |
| `attributes` | Attribute schema per entity type |
| `attribute_enums` | Allowed options for `select`-type attributes |
| `entity_attribute` | Polymorphic typed values per entity instance |
| `entity_translations` | Localized labels for any model |

> [!NOTE]
> To add custom columns to the `attributes` table (e.g. `measurement_id`), create a separate migration in your application after publishing.

## Overriding Models

Every model inside the package is resolved via the `eav.models` config key. To extend a model, create a subclass and point the config at it:

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

All six keys can be overridden independently:

| Key | Default class |
|---|---|
| `attribute` | `Jurager\Eav\Models\Attribute` |
| `attribute_type` | `Jurager\Eav\Models\AttributeType` |
| `attribute_group` | `Jurager\Eav\Models\AttributeGroup` |
| `attribute_enum` | `Jurager\Eav\Models\AttributeEnum` |
| `entity_attribute` | `Jurager\Eav\Models\EntityAttribute` |
| `entity_translation` | `Jurager\Eav\Models\EntityTranslation` |
| `locale` | `Jurager\Eav\Models\Locale` |

> [!NOTE]
> Relations and observers defined in the base models remain active in subclasses. Only override what you need to extend.
