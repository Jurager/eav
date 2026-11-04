---
title: Advanced usage
weight: 80
---

Use these guides when your application needs more than a shared attribute schema:

## Eager loading attribute values

[Load attribute values](eager-loading.md) before rendering collections.

## Attribute inheritance

[Scope attributes by categories](inheritance.md) and inherit definitions through their parent categories.

## Variants

[Let variants inherit values](variants.md) while keeping their own editable attributes.

## Scoping attributes via a related model

Choose which attributes an entity can use through a [related model](inheritance.md).

## Scoped uniqueness

[Limit unique values to a scope](inheritance.md#scoped-uniqueness), such as a category tree.

## Scout integration

[Index attribute values with Scout](search.md) and configure Meilisearch filters.

## Search indexing

Search documents update automatically after values are saved. See [indexing attributes](search.md).

### Deleting attributes permanently

Keep a restore window before [purging deleted attributes](schema.md#deleting-attributes-permanently).

## Schema cache

Schema changes made through package models and facades invalidate the cache automatically. Set `EAV_CACHE_STORE` to a store shared by your application instances.

After raw SQL, mass updates, or a database restore, clear the schema cache:

```bash
php artisan eav:clear
```

## JSON:API sparse fieldsets

To use EAV codes in sparse fieldsets with `jurager/microservice`, add `InteractsWithSparseFieldsets` and the resource contracts:

```php
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Concerns\InteractsWithSparseFieldsets;
use Jurager\Microservice\JsonApi\Contracts\MapsFieldsToRelations;
use Jurager\Microservice\JsonApi\Contracts\ProvidesEagerLoads;

class Product extends Model implements Attributable, MapsFieldsToRelations, ProvidesEagerLoads
{
    use HasAttributes, InteractsWithSparseFieldsets;
}
```

A request such as `?fields[products]=name,color&include=attribute_values` loads only the requested attribute values, including inherited ones.

## Events

[Listen for schema and value changes](events.md) using Laravel listeners.
