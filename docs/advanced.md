---
title: Advanced
weight: 80
---

## Eager Loading Attribute Values

The `HasAttributes` trait exposes the `attribute_values` relation (a `MorphMany` to `entity_attribute`) along with a static helper that declares every sub-relation required to fully hydrate attribute values without N+1 queries.

### Accessing Raw Rows

You may use the relation directly anywhere you need raw access to the EAV rows:

```php
$product->load('attribute_values');
$product->attribute_values; // Collection<EntityAttribute>
```

### Hydrating Typed Field Instances

`AttributeManager::values()` transforms the raw rows into typed `Field` instances, resolving text, select, boolean, and so on. The manager checks whether `attribute_values` is already loaded on the model and uses the in-memory collection when available, avoiding a new query.

For this pre-loaded path to work without lazy-loading `attribute->type`, `attribute->enums`, and translations, those sub-relations must be present on the already-loaded `attribute_values`. You should load them in one batch before serialization:

```php
$products->load([
    'attribute_values' => fn ($q) => $q->with([
        'attribute.type',
        'attribute.group.translations',
        'attribute.translations',
        'attribute.enums.translations',
        'translations',
    ]),
]);
```

### Reusing the Canonical Relation List

To avoid repeating the relation list across projects, `HasAttributes` exposes the canonical set as a static method:

```php
Product::attributeRelations();
// [
//   'attributeValues.attribute.type',
//   'attributeValues.attribute.group.translations',
//   'attributeValues.attribute.translations',
//   'attributeValues.attribute.enums.translations',
//   'attributeValues.translations',
// ]
```

The list is intentionally structured as top-level-prefixed paths so you may pass it to `load()` or `with()` directly on the entity collection:

```php
$products->load(Product::attributeRelations());

// or on a query:
Product::query()->with(Product::attributeRelations())->get();
```

When serializing a collection of entities and calling `values()` on each, batch-load with `attributeRelations()` beforehand. This ensures `values()` uses the in-memory collection and never triggers per-model queries:

```php
$products->load(Product::attributeRelations());

foreach ($products as $product) {
    $values = $product->eav()->values(); // no DB queries
}
```

## Attribute Inheritance

Entities arranged in a hierarchy may inherit the attribute schema of their ancestors. A common use case is a category tree where a subcategory exposes every attribute from its parent categories.

To enable inheritance, override `shouldInheritAttributes()` on the scope model:

```php
class Category extends Model implements Attributable
{
    use HasAttributes, NodeTrait;

    public function shouldInheritAttributes(): bool
    {
        return $this->is_inherits_properties && $this->parent_id !== null;
    }
}
```

When `attributeScopeModel()` returns a non-null class, the inheritance resolver is called automatically to expand the scope with ancestor entities.

### Tree Detection Strategies

The inheritance resolver detects the tree strategy automatically:

- **Nested set** (`_lft`/`_rgt` columns, for example via `kalnoy/nestedset`) — every ancestor is resolved in a single bounds query.
- **Parent ID chain** — walks `parent_id` level by level, up to ten levels deep.

Inheritance stops at the first ancestor where `shouldInheritAttributes()` returns `false`.

Given the following tree:

```
Root (inherits: false)
└── Electronics (inherits: true)
    └── Phones (inherits: true)
```

A product assigned to `Phones` sees attributes from `Phones` and `Electronics`. `Root` attributes are excluded because inheritance stops there.

## Events

`SchemaManager` dispatches a domain event after every successful mutation. All events live in the `Jurager\Eav\Events\` namespace:

| Event | Property | When |
|---|---|---|
| `AttributeCreated` | `Attribute $attribute` | Attribute created |
| `AttributeUpdated` | `Attribute $attribute` | Attribute updated (fresh instance) |
| `AttributeDeleted` | `Attribute $attribute` | Attribute deleted (pre-deletion snapshot) |
| `AttributeGroupCreated` | `AttributeGroup $group` | Group created |
| `AttributeGroupUpdated` | `AttributeGroup $group` | Group updated (fresh instance) |
| `AttributeGroupDeleted` | `AttributeGroup $group` | Group deleted (snapshot) |
| `AttributeEnumCreated` | `AttributeEnum $enum` | Enum value created |
| `AttributeEnumUpdated` | `AttributeEnum $enum` | Enum value updated (fresh instance) |
| `AttributeEnumDeleted` | `AttributeEnum $enum` | Enum value deleted (snapshot) |

Laravel auto-discovers listeners by type-hint on `handle()`, so no manual registration is needed:

```php
namespace App\Listeners;

use Jurager\Eav\Events\AttributeCreated;

class AttachAttributeToDefaultCategory
{
    public function handle(AttributeCreated $event): void
    {
        if ($event->attribute->entity_type === 'product') {
            // attach to default category…
        }
    }
}
```

## Search Indexing

The package integrates with [Laravel Scout](https://laravel.com/docs/scout). An observer and queued jobs keep the search index in sync automatically when attribute definitions change.

### Building the Search Array

`HasSearchableAttributes` provides `toSearchableArray()` and `shouldBeSearchable()` that delegate to `AttributeManager::indexData()`. Attributes with `searchable: true` **or** `filterable: true` are included so that Meilisearch (and other engines that require data to be present for filtering) can work correctly.

To add model-specific fields alongside attribute data, you may override `toSearchableArray()`:

```php
public function toSearchableArray(): array
{
    $data = $this->eav()?->indexData() ?? [];

    return ['id' => (string) $this->getScoutKey(), 'code' => $this->code, ...$data];
}
```

### Automatic Index Sync

`AttributeObserver` is registered automatically. It dispatches jobs when attribute definitions change:

| Event | Condition | Jobs dispatched |
|---|---|---|
| `created` | `filterable: true` | `SyncFilterable` |
| `updated` | `searchable` flag changed | `SyncSearchable` |
| `updated` | `filterable` flag changed | `SyncFilterable`, `SyncSearchable` |
| `deleted` | `searchable: true` | `SyncSearchable` |
| `deleted` | `filterable: true` | `SyncFilterable` |
| `restored` | `searchable: true` | `SyncSearchable` |
| `restored` | `filterable: true` | `SyncFilterable` |

`SyncSearchable` implements `ShouldQueue` and `ShouldBeUnique`. It finds every entity instance with a stored value for the changed attribute and calls `->searchable()` on the collection, re-populating document data in the index.

### Meilisearch: Syncing filterableAttributes

`SyncFilterable` keeps the `filterableAttributes` index setting in sync with the current set of `filterable: true` attributes. It is a no-op when Scout is not installed or the active driver is not Meilisearch.

When dispatched, it:

1. Queries all `filterable: true` attributes for the entity type.
2. Reads the current `filterableAttributes` from the Meilisearch index.
3. Preserves all non-EAV paths (e.g. `id`, `is_active`) that were set outside this package.
4. Replaces all `attributes.*` paths with the fresh set.

This means you never need to manually call `scout:sync-index-settings` when attribute definitions change — the job handles it automatically.

### Custom Field Types and filterableKeys

When building a custom field type, you may override `filterableKeys()` to control which index paths are registered as filterable in Meilisearch. The default returns `['{code}']`; `Select` returns `['{code}', '{code}_code']` so that faceting on the string enum code is available alongside the integer ID:

```php
public function filterableKeys(): array
{
    return [$this->code(), "{$this->code()}_label"];
}
```

`SyncFilterable` prefixes each key with `attributes.` when registering with Meilisearch.

### Cleanup on Permanent Deletion

When an attribute is force-deleted, `PruneAttribute` is dispatched. It permanently removes every `entity_attribute` row for that attribute and flushes the `Select` enum cache.

This two-step process — soft-delete → re-index → force-delete → prune — gives you a window to restore an attribute before its data is permanently removed.

### Running the Queue Worker

All jobs are queued, so you should ensure a queue worker is running:

```bash
php artisan queue:work
```
