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
- **Parent ID chain** — walks `parent_id` level by level, up to the configured limit.

Inheritance stops at the first ancestor where `shouldInheritAttributes()` returns `false`.

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

## Variants

Entities of the same type may form pairs: a product model and its trade offers, a garment and its sizes.

The variant stores only what makes it different and reads the rest off its parent.

Point the model at the relation holding the parent and the package takes it from there:

```php
class Product extends Model implements Attributable
{
    use HasAttributes;

    protected function attributeParentRelationName(): ?string
    {
        return 'parent';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }
}
```

An entity is a variant while that relation resolves to an entity; everything with an empty foreign key stays a plain entity and behaves exactly as before.

### Which Side Holds an Attribute

The `held_by` column says which side fills an attribute in, and `inherit_from_parent` whether a variant falls back to the parent's value — see [attribute flags](field-types.md#attribute-flags):

| Value                 | Parent      | Variant                                   |
|-----------------------|-------------|-------------------------------------------|
| `held_by: parent`     | fills it in | never                                     |
| `held_by: variant`    | never       | fills it in                               |
| `held_by: both`       | fills it in | may override the parent's value           |
| `inherit_from_parent` | fills it in | reads the parent's value when it has none |

Reading is transparent — `value()`, `values()` and the search document return the effective value,
whichever side stores it:

```php
$offer->eav()->value('color');   // its own — held_by: variant
$offer->eav()->value('name');    // the model's — inherit_from_parent
```

Writing is not: `validate()` rejects an attribute held by the other side, so a value never lands where nothing will read it.

### Scope and Indexing

A variant usually carries no scope of its own — an offer belongs to the categories of its model — so `attributeScopeIds()` falls back to the parent's scope, and both sides resolve the same schema.

Search documents follow suit: a variant is indexed with its inherited values, the relations they come from are eager-loaded for the whole batch, and writing values on a parent re-queues the documents of its variants.

## Scoping Attributes via a Related Model

By default, every entity shares one global attribute schema per entity type. To give each product its own schema based on its categories instead, override `attributeScopeModel()`:

```php
protected static function attributeScopeModel(): ?string
{
    return Category::class;
}
```

That alone is enough if the product's relation to its scope entities follows Laravel's own pluralized, camelCase naming convention — `attributeScopeModel()` returning `Category::class` resolves to a `categories()` relation. The package resolves the schema through those categories, following [attribute inheritance](#attribute-inheritance) if enabled.

If your relation isn't named that way, override `attributeScopeRelationName()`:

```php
protected function attributeScopeRelationName(): ?string
{
    return 'productCategories';
}
```

The scope model exposes its attributes through `attributeScopeRelation()`, which defaults to the standard `entity_attribute` relation. Override it with a dedicated pivot table to keep attribute assignment separate from any EAV values stored on the category itself:

```php
// Category.php
public function attributeScopeRelation(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id');
}
```

`$product->availableAttributes()` now resolves to the union of attributes assigned to every category the product belongs to.

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


## Scout Integration

To include EAV values in your Laravel Scout search index, add the `HasSearchableAttributes` trait to your model. You must also use `Laravel\Scout\Searchable` and resolve the trait method conflict explicitly:

```php
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Concerns\HasSearchableAttributes;
use Jurager\Eav\Contracts\Attributable;
use Laravel\Scout\Searchable;

class Product extends Model implements Attributable
{
    use HasAttributes, HasSearchableAttributes, Searchable {
        HasSearchableAttributes::toSearchableArray insteadof Searchable;
        HasSearchableAttributes::shouldBeSearchable insteadof Searchable;
    }
    // ...
}
```

This setup is the first step. The package also provides automatic index syncing when attributes change. See the **Search Indexing** section below for the full workflow.

## Search Indexing

The package integrates with [Laravel Scout](https://laravel.com/docs/scout). An observer and queued jobs keep the search index in sync automatically when attribute definitions change.

### Building the Search Array

`HasSearchableAttributes` provides `toSearchableArray()` and `shouldBeSearchable()` that delegate to `AttributeManager::indexData()`. Attributes with `searchable: true` **or** `filterable: true` are included so that Meilisearch can work correctly.

To add model-specific fields alongside attribute data, override `toSearchableArray()`:

```php
public function toSearchableArray(): array
{
    $data = $this->eav()->indexData();

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

## Events

Observers dispatch a domain event after every successful mutation. All events live in the `Jurager\Eav\Events\` namespace:

| Event                   | Property                | When                                    |
|-------------------------|-------------------------|-----------------------------------------|
| `AttributeCreated`      | `Attribute $attribute`  | Attribute created                       |
| `AttributeUpdated`      | `Attribute $attribute`  | Attribute updated                       |
| `AttributeDeleted`      | `Attribute $attribute`  | Attribute soft-deleted or force-deleted |
| `AttributeGroupCreated` | `AttributeGroup $group` | Group created                           |
| `AttributeGroupUpdated` | `AttributeGroup $group` | Group updated                           |
| `AttributeGroupDeleted` | `AttributeGroup $group` | Group deleted                           |
| `AttributeEnumCreated`  | `AttributeEnum $enum`   | Enum value created                      |
| `AttributeEnumUpdated`  | `AttributeEnum $enum`   | Enum value updated                      |
| `AttributeEnumDeleted`  | `AttributeEnum $enum`   | Enum value deleted                      |

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
