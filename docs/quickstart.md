---
title: Quickstart
weight: 20
---

## Making a Model Attributable

Implement `Attributable` and use `HasAttributes`. Only `getEavEntityType()` is required:

```php
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;

    public function getEavEntityType(): string
    {
        return 'product';
    }
}
```

Register the entity type in the morph map:

```php
Relation::morphMap([
    'product'  => Product::class,
    'category' => Category::class,
]);
```

## Scout Search

Add `HasSearchableAttributes` to include EAV values in the search index. Resolve the trait conflict explicitly:

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

See [Advanced](advanced.md#search-indexing) for the full indexing workflow.

## Scoping Attributes Via a Related Model

By default every entity shares one global attribute schema per entity type. To give each product its own schema based on its categories instead, override `attributeScopeModel()` and `getEavScopes()`:

```php
protected static function attributeScopeModel(): ?string
{
    return Category::class;
}

public function getEavScopes(): array
{
    $this->loadMissing('categories');

    return $this->categories->pluck('id')->toArray();
}
```

`getEavScopes()` returns the related scope IDs — here, the product's category IDs. The package resolves the schema through those categories, following [attribute inheritance](advanced.md#attribute-inheritance) if enabled.

The scope model exposes its attributes through `attributeScopeRelation()`, which defaults to the standard `entity_attribute` relation. Override it with a dedicated pivot table to keep attribute assignment separate from any EAV values stored on the category itself:

```php
// Category.php
public function attributeScopeRelation(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id');
}
```

`$product->availableAttributes()` now resolves to the union of attributes assigned to every category the product belongs to.
