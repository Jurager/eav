---
title: Search Indexing
weight: 100
---

# Search Indexing

The package ships with two queued jobs that keep a search index in sync when attribute definitions change.

## SyncSearchable

Dispatched automatically by `AttributeObserver` when:

- An attribute's `searchable` flag is changed.
- An attribute is soft-deleted.

The job finds all entity instances that have a stored value for the changed attribute and re-queues them for search indexing.

## PruneAttribute

Dispatched by `SyncSearchable` when the attribute is soft-deleted. It waits 10 minutes (allowing time for restores) then force-deletes the attribute and all its stored values from `entity_attribute`.

## Deduplication

Both jobs implement `ShouldQueue` and `ShouldBeUnique`. Duplicate dispatches for the same `(entity_type, attribute_id)` pair within the uniqueness window are safely ignored.

## Observer Registration

`AttributeObserver` is registered automatically by `EavServiceProvider` using the model class bound to `eav.models.attribute` in the config. No manual registration is needed.

## Search Array

To expose attribute values to a search engine (e.g. Laravel Scout), call `getIndexData()` on the `AttributeManager`:

```php
public function toSearchableArray(): array
{
    $data = $this->attributes()?->getIndexData() ?? [];

    return ['id' => (string) $this->getScoutKey(), ...$data];
}
```

`getIndexData()` returns only attributes where `searchable: true`, flattening localizable values into arrays.
