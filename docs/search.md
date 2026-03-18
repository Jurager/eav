---
title: Search Indexing
weight: 100
---

# Search Indexing

The package integrates with [Laravel Scout](https://laravel.com/docs/scout) out of the box. It ships with an observer and two queued jobs that keep the search index in sync automatically when attribute definitions change.

## Search Array

The `HasAttributes` trait provides `toSearchableArray()` and `shouldBeSearchable()` that delegate to `AttributeManager::indexData()`. Only attributes with `searchable: true` are included.

If you need custom fields alongside attribute data, override `toSearchableArray()` in your model:

```php
public function toSearchableArray(): array
{
    $data = $this->attributes()?->indexData() ?? [];

    return ['id' => (string) $this->getScoutKey(), 'code' => $this->code, ...$data];
}
```

`indexData()` flattens localizable values into arrays of strings. The result is memoized for the lifetime of the manager instance.

## Automatic Sync — AttributeObserver

`AttributeObserver` is registered automatically by `EavServiceProvider` using the model class bound to `eav.models.attribute` in config. No manual registration is needed.

It dispatches `SyncSearchable` in two cases:

| Event | Condition |
|---|---|
| `updated` | The attribute's `searchable` flag changed |
| `deleted` | The attribute was soft-deleted |

## SyncSearchable Job

`Jurager\Eav\Jobs\SyncSearchable`

Implements `ShouldQueue` and `ShouldBeUnique`. Duplicate dispatches for the same `(entity_type, attribute_id)` pair are safely ignored within the uniqueness window.

**What it does:**

1. Resolves the entity model class from the morph map.
2. Finds all entity instances that have a stored value for the changed attribute.
3. Calls `->searchable()` on that collection to re-index them with Scout.
4. If the attribute was soft-deleted, dispatches `PruneAttribute`.

```php
// Dispatched internally by AttributeObserver:
SyncSearchable::dispatch($attribute->entity_type, $attribute->id);
```

## PruneAttribute Job

`Jurager\Eav\Jobs\PruneAttribute`

Dispatched by `SyncSearchable` after a soft-deleted attribute has been re-indexed. It force-deletes the attribute record (and via cascade, all its `entity_attribute` rows).

This two-step approach gives a window for restoring a soft-deleted attribute before its data is permanently removed.

```php
// Dispatched internally by SyncSearchable after re-indexing a trashed attribute:
PruneAttribute::dispatch($attributeId);
```

## Queueing

Both jobs are queued. Make sure a queue worker is running:

```bash
php artisan queue:work
```

To use a dedicated queue, publish the config and set:

```php
// config/eav.php
'queue' => 'search',
```
