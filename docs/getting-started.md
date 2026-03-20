---
title: Getting Started
weight: 30
---

# Getting Started

## Make a Model Attributable

Add the `HasAttributes` trait and implement the `Attributable` contract. Only `getAttributeEntityType()` is required — all other contract methods have defaults in `HasAttributes`:

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

Register the morph map so the entity type resolves to the correct model:

```php
Relation::morphMap([
    'product'  => Product::class,
    'category' => Category::class,
]);
```

## Attribute Scope

By default all entities of the same type share the same attribute schema (`global` scope).

If attribute definitions are scoped through a related model — for example, a product's attributes are defined on its categories — override `getAttributeScope()`, `getAttributeRelationModel()`, and `getDefaultParameters()`:

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

The related model must also implement `Attributable` and override `available_attributes()` with a `BelongsToMany` relation pointing to the `attributes` table:

```php
// Category.php
public function available_attributes(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')
        ->withPivot(['id', 'created_at']);
}
```

> [!NOTE]
> `available_attributes()` is defined in the `Attributable` contract and has a default implementation in `HasAttributes` that returns `null`. Override it on any model that acts as an attribute scope provider. The package calls this method directly — no dynamic method name resolution.
