---
title: Quickstart
weight: 20
---

## Making a Model Attributable

Implement `Attributable` and use `HasAttributes`. Only `attributeEntityType()` is required:

```php
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;

    public function attributeEntityType(): string
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

To give each product its own attribute schema based on its categories, override two methods:

```php
protected static function attributeScopeModel(): string
{
    return Category::class;
}

public function attributeParameters(): array
{
    $this->loadMissing('categories');

    return $this->categories->pluck('id')->toArray();
}
```

The scope-provider model (`Category`) must override `attributes()` with a `BelongsToMany`:

```php
// Category.php
public function attributes(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')
        ->withPivot(['id', 'created_at']);
}
```

`HasAttributes` provides a default `attributes()` returning a `MorphToMany` via `entity_attribute` — available on every Attributable model. Scope providers override it with a `BelongsToMany` so the package can resolve the schema through that relation.
