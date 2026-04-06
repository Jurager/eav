---
title: Advanced
weight: 80
---

# Advanced

## Attribute Inheritance

Entities in a hierarchy can inherit the attribute schema of their ancestors. A typical use case is a category tree where a subcategory exposes all attributes from its parent categories.

Override `shouldInheritAttributes()` to enable it:

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

**Tree strategies** — the resolver detects automatically:

- **Nested set** (`_lft`/`_rgt` columns, e.g. via `kalnoy/nestedset`) — all ancestors in a single bounds query.
- **Parent ID chain** — walks `parent_id` level by level (max 10 levels deep).

Inheritance stops at the first ancestor where `shouldInheritAttributes()` returns `false`.

**Example** — given:
```
Root (inherits: false)
└── Electronics (inherits: true)
    └── Phones (inherits: true)
```
A product assigned to `Phones` sees attributes from `Phones` and `Electronics`. `Root` attributes are excluded.

---

## Events

`SchemaManager` dispatches a domain event after every successful mutation. All events are in the `Jurager\Eav\Events\` namespace.

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

Laravel auto-discovers listeners by type-hint in `handle()` — no manual registration needed:

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

---

## Search Indexing

The package integrates with [Laravel Scout](https://laravel.com/docs/scout). An observer and two queued jobs keep the search index in sync automatically when attribute definitions change.

### Search array

`HasSearchableAttributes` provides `toSearchableArray()` and `shouldBeSearchable()` that delegate to `AttributeManager::indexData()`. Only attributes with `searchable: true` are included.

To add model-specific fields alongside attribute data, override `toSearchableArray()`:

```php
public function toSearchableArray(): array
{
    $data = $this->attributes()?->indexData() ?? [];

    return ['id' => (string) $this->getScoutKey(), 'code' => $this->code, ...$data];
}
```

### Automatic sync

`AttributeObserver` is registered automatically. It dispatches `SyncSearchable` when:

| Event | Condition |
|---|---|
| `updated` | The `searchable` flag changed on the attribute |
| `deleted` | The attribute was soft-deleted |

`SyncSearchable` implements `ShouldQueue` and `ShouldBeUnique`. It finds all entity instances with a stored value for the changed attribute and calls `->searchable()` on the collection.

### Deletion cleanup

When an attribute is force-deleted, `PruneAttribute` is dispatched. It permanently removes all `entity_attribute` rows for that attribute and flushes the `SelectField` enum cache.

> [!NOTE]
> This two-step process (soft-delete → re-index → force-delete → prune) gives a window to restore an attribute before its data is permanently removed.

### Queue

Both jobs are queued. Ensure a queue worker is running:

```bash
php artisan queue:work
```
