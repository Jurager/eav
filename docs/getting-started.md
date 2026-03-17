---
title: Getting Started
weight: 30
---

# Getting Started

## Make a Model Attributable

Add the `HasAttributes` trait and implement the `Attributable` contract:

```php
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;
use Illuminate\Database\Eloquent\Builder;

class Product extends Model implements Attributable
{
    use HasAttributes;

    public function getAttributeEntityType(): string
    {
        return 'product';
    }

    public function getDefaultParameters(): array
    {
        return [];
    }
}
```

## Attribute Scope

By default all entities of the same type share the same attribute schema (`global` scope).

If attribute definitions are scoped through a related model — for example, a product's attributes are defined on its categories — override `getAttributeScope()` and `getAttributeRelationModel()`:

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

The related model must also implement `Attributable` and expose an `available{EntityType}Attributes()` `BelongsToMany` relation pointing to the `attributes` table.

```php
// Category.php
public function availableProductAttributes(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')
        ->withPivot(['id', 'created_at']);
}
```

> [!NOTE]
> The method name is resolved dynamically: `available` + ucfirst(entity type) + `Attributes`. For entity type `product` the method must be named `availableProductAttributes()`.
