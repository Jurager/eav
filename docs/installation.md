---
title: Installation
weight: 20
---

# Installation

Install the package with Composer:

```bash
composer require jurager/eav
```

The package registers itself automatically via Laravel's package auto-discovery.

## Publish Configuration

```bash
php artisan vendor:publish --tag=eav-config
```

This creates `config/eav.php` where you can override model bindings and register custom field types.

## Database

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=eav-migrations
php artisan migrate
```

The package creates these tables:

| Table | Description |
|---|---|
| `locales` | Supported locale codes (`en`, `ru`, …) |
| `attribute_types` | Field type definitions (`text`, `number`, `select`, …) |
| `attribute_groups` | Grouping of attributes for display |
| `attributes` | Attribute schema per entity type |
| `attribute_enums` | Allowed options for `select`-type attributes |
| `entity_attribute` | Polymorphic attribute values per entity instance |
| `entity_translations` | Localized labels for any entity |

> [!NOTE]
> If your application requires additional columns on the `attributes` table (e.g. `measurement_id`), add them in a separate migration in your application after publishing.
