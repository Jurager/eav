---
title: Quickstart
weight: 20
---

# Quickstart

## Make a Model Attributable

Implement `Attributable` and add the `HasAttributes` trait. Only `getAttributeEntityType()` is required:

```php
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;

    public function getAttributeEntityType(): string
    {
        return 'product';
    }
}
```

Register the entity type in the morph map so the package can resolve models by their type string:

```php
// AppServiceProvider::boot()
Relation::morphMap([
    'product'  => Product::class,
    'category' => Category::class,
]);
```

## With Scout Search

If the model uses [Laravel Scout](https://laravel.com/docs/scout), add `HasSearchableAttributes` and resolve the trait conflict:

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

    public function getAttributeEntityType(): string
    {
        return 'product';
    }
}
```

## Scoping Attributes via a Related Model

By default all entities of the same type share one attribute schema (`global` scope). If attribute definitions are managed per related model — for example, each product category has its own set of attributes — override three methods:

```php
protected function getAttributeScope(): string
{
    return 'byRelation';
}

protected static function getAttributeRelationModel(): string
{
    return Category::class;
}

public function getDefaultParameters(): array
{
    $this->loadMissing('categories');

    return $this->categories->pluck('id')->toArray();
}
```

The relation model must also implement `Attributable` and expose `available_attributes()` as a `BelongsToMany` pointing to the `attributes` table:

```php
// Category.php
public function available_attributes(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')
        ->withPivot(['id', 'created_at']);
}
```

> [!NOTE]
> `available_attributes()` is declared in the `Attributable` contract and defaults to `null` in `HasAttributes`. Override it only on models that act as scope providers (e.g. `Category`).
