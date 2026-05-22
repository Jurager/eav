---
title: Quickstart
weight: 20
---

## Making a Model Attributable

To attach EAV attributes to an Eloquent model, you should implement the `Attributable` contract and use the `HasAttributes` trait. Only the `attributeEntityType()` method is required — it returns a short string that identifies the entity type in the schema:

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

You should also register the entity type in the morph map so the package can resolve models back to their type string:

```php
// AppServiceProvider::boot()
Relation::morphMap([
    'product'  => Product::class,
    'category' => Category::class,
]);
```

## Integrating With Scout Search

If your model uses [Laravel Scout](https://laravel.com/docs/scout), you may add the `HasSearchableAttributes` trait to automatically include EAV values in the search index. The Searchable trait shares two methods with `HasSearchableAttributes`, so you need to resolve the conflict explicitly:

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

    public function attributeEntityType(): string
    {
        return 'product';
    }
}
```

See the [Advanced documentation](advanced.md#search-indexing) for the full search indexing workflow.

## Scoping Attributes Via a Related Model

By default, all entities of a given type share a single attribute schema (global scope). When the schema needs to be managed per related model — for example, each product category exposes its own set of attributes — you may override two methods on the entity:

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

The relation model must also implement `Attributable` and expose an `available_attributes()` relation pointing at the `attributes` table:

```php
// Category.php
public function available_attributes(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')
        ->withPivot(['id', 'created_at']);
}
```

The `available_attributes()` method is declared in the `Attributable` contract and defaults to `null` in `HasAttributes`. You should override it only on models that act as scope providers — for instance, the `Category` model — not on the entities that consume the schema.
