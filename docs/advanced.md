---
title: Advanced
weight: 80
---

## Eager Loading Attribute Values

The `HasAttributes` trait exposes the `attribute_values` relation (a `MorphMany` to `entity_attribute`).

### Accessing Raw Rows

```php
$product->load('attribute_values');
$product->attribute_values; // Collection<EntityAttribute>
```

### Hydrating Typed Field Instances

`AttributeManager::values()` transforms the raw rows into typed `Field` instances with a resolved `value` property. When `attribute_values` is already loaded on the model, the in-memory collection is used — no additional query. Missing sub-relations are batch-loaded automatically.

You may filter by attribute code or paginate:

```php
$product->eav()->values();                    // Collection — all attributes
$product->eav()->values(['color', 'weight']); // Collection — specific codes only
$product->eav()->values(paginated: 15);       // LengthAwarePaginator
```

For best performance on collections, eager-load everything upfront before serialization:

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

foreach ($products as $product) {
    $values = $product->eav()->values(); // no DB queries
}
```

## Attribute Inheritance

Entities arranged in a hierarchy may inherit the attribute schema of their ancestors. A common use case is a category tree where a subcategory exposes every attribute from its parent categories.

To enable inheritance, override `shouldInheritEavAttributes()` on the scope model:

```php
class Category extends Model implements Attributable
{
    use HasAttributes, NodeTrait;

    public function shouldInheritEavAttributes(): bool
    {
        return $this->is_inherits_properties && $this->parent_id !== null;
    }
}
```

When `attributeScopeModel()` returns a non-null class, the inheritance resolver is called automatically to expand the scope with ancestor entities.

### Tree Detection Strategies

The inheritance resolver detects the tree strategy automatically:

- **Nested set** (`_lft`/`_rgt` columns, for example via `kalnoy/nestedset`) — every ancestor is resolved in a single bounds query.
- **Parent ID chain** — walks `parent_id` level by level, up to the configured limit.

Inheritance stops at the first ancestor where `shouldInheritEavAttributes()` returns `false`.

Given the following tree:

```
Root (inherits: false)
└── Electronics (inherits: true)
    └── Phones (inherits: true)
```

A product assigned to `Phones` sees attributes from `Phones` and `Electronics`. `Root` attributes are excluded because inheritance stops there.

### Inheritance Depth

The parent-ID strategy walks up to `eav.max_inheritance_depth` levels (default `10`). If the chain exceeds this limit, a `CircularInheritanceException` is thrown with the IDs that could not be resolved. This catches circular `parent_id` references before they cause an infinite loop:

```php
// config/eav.php
'max_inheritance_depth' => 20,
```

## Scoped Uniqueness

By default, the `unique` attribute flag enforces uniqueness globally across all entity instances. To restrict the check to a narrower scope — for example, unique within a category subtree — override `attributeUniqueScopes()` on the model:

```php
public static function attributeUniqueScopes(): array
{
    return [
        'code' => function (Builder $query, self $entity): void {
            $rootId = $entity->parent_id === null
                ? $entity->id
                : static::query()->whereAncestorOf($entity->id)->whereNull('parent_id')->value('id');

            if ($rootId) {
                $query->whereIn('entity_id', static::query()->whereDescendantOrSelf($rootId)->select('id'));
            }
        },
    ];
}
```

The array key is the attribute code. The closure receives the `entity_attribute` Builder and the entity being validated; add `where` conditions to limit the uniqueness scope. Attributes not listed in the array use global uniqueness.

## Events

Observers dispatch a domain event after every successful mutation. All events live in the `Jurager\Eav\Events\` namespace:

| Event | Property | When |
|---|---|---|
| `AttributeCreated` | `Attribute $attribute` | Attribute created |
| `AttributeUpdated` | `Attribute $attribute` | Attribute updated |
| `AttributeDeleted` | `Attribute $attribute` | Attribute soft-deleted or force-deleted |
| `AttributeGroupCreated` | `AttributeGroup $group` | Group created |
| `AttributeGroupUpdated` | `AttributeGroup $group` | Group updated |
| `AttributeGroupDeleted` | `AttributeGroup $group` | Group deleted |
| `AttributeEnumCreated` | `AttributeEnum $enum` | Enum value created |
| `AttributeEnumUpdated` | `AttributeEnum $enum` | Enum value updated |
| `AttributeEnumDeleted` | `AttributeEnum $enum` | Enum value deleted |

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

`HasSearchableAttributes` provides `toSearchableArray()` and `shouldBeSearchable()` that delegate to `AttributeManager::indexData()`. Attributes with `searchable: true` **or** `filterable: true` are included so that Meilisearch can work correctly.

To add model-specific fields alongside attribute data, override `toSearchableArray()`:

```php
public function toSearchableArray(): array
{
    $data = $this->eav()?->indexData() ?? [];

    return ['id' => (string) $this->getScoutKey(), 'code' => $this->code, ...$data];
}
```

### Automatic Index Sync

`AttributeObserver` is registered automatically. Whenever an attribute is created, updated, deleted, force-deleted, or restored, it dispatches `SyncSearchable` and/or `SyncFilterable` for whichever of the `searchable` / `filterable` flags are `true` (on update, only when that flag actually changed). Force-deleting also dispatches `PruneAttribute` to remove the attribute's stored values.

`AttributeCreated`, `AttributeUpdated`, and `AttributeDeleted` (fired on both soft- and force-delete) dispatch alongside the sync jobs; `restored` fires no domain event.

`SyncSearchable` implements `ShouldQueue` and `ShouldBeUnique`. It finds every entity instance with a stored value for the changed attribute and calls `->searchable()` on the collection.

### Meilisearch: Syncing filterableAttributes

`SyncFilterable` keeps the `filterableAttributes` index setting in sync with the current set of `filterable: true` attributes. It is a no-op when Scout is not installed or the active driver is not Meilisearch.

When dispatched, it:

1. Queries all `filterable: true` attributes for the entity type.
2. Reads the current `filterableAttributes` from the Meilisearch index.
3. Preserves all non-EAV paths (e.g. `id`, `is_active`) that were set outside this package.
4. Replaces all `attributes.*` paths with the fresh set.

### Custom Field Types and filterableKeys

When building a custom field type, you may override `filterableKeys()` to control which index paths are registered as filterable. The default (used by all built-in types, including `Select`) returns a single `['{code}']` path:

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
