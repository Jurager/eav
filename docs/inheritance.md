---
title: Scoping and inheriting attributes
weight: 81
---

To choose a product's attributes through related categories, override `attributeScopeModel()`:

```php
protected static function attributeScopeModel(): ?string
{
    return Category::class;
}
```

The relation name defaults to Laravel's plural model name: `Category` uses `categories()`. Inherited category attributes are included.

If your relation isn't named that way, override `attributeScopeRelationName()`:

```php
protected function attributeScopeRelationName(): ?string
{
    return 'productCategories';
}
```

Override `attributeScopeRelation()` on the scope model to use a dedicated assignment table:

```php
// Category.php
public function attributeScopeRelation(): BelongsToMany
{
    return $this->belongsToMany(Attribute::class, 'category_attribute', 'category_id', 'attribute_id')
        ->using(\Jurager\Eav\Models\AttributeScope::class);
}
```

`$product->availableAttributes()` now resolves to the union of attributes assigned to every category the product belongs to.

`AttributeScope` clears cached schemas when assignments change. After raw SQL changes, call
`app(\Jurager\Eav\Registry\SchemaRegistry::class)->forget()`.

## Scoped uniqueness

The `unique` flag applies across entities of the same type. Override `attributeUniqueScopes()` to narrow it, for example to a category tree:

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

## Inheriting from parent categories

Scope models may inherit attribute definitions from their ancestors.

To enable inheritance, override `shouldInheritAttributes()` on the scope model. This example uses `kalnoy/nestedset`:

```php
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Contracts\ShouldUseNestedSet;
use Kalnoy\Nestedset\NodeTrait;

class Category extends Model implements Attributable, ShouldUseNestedSet
{
    use HasAttributes, NodeTrait;

    public function shouldInheritAttributes(): bool
    {
        return $this->is_inherits_properties && $this->parent_id !== null;
    }
}
```

The scope model is the category class returned by `attributeScopeModel()`.

### Tree detection strategies

Choose the tree implementation:

- Implement `ShouldUseNestedSet` with `NodeTrait` for a nested-set tree.
- Otherwise, use `parent_id` or override `inheritanceParentKey()`.

Inheritance stops at the first ancestor where `shouldInheritAttributes()` returns `false`.

For example:

```
Root (inherits: false)
└── Electronics (inherits: true)
    └── Phones (inherits: true)
```

A product assigned to `Phones` sees attributes from `Phones` and `Electronics`. `Root` attributes are excluded because inheritance stops there.

### Inheritance depth

Set `eav.max_inheritance_depth` to limit parent traversal. Exceeding it throws `CircularInheritanceException`:

```php
// config/eav.php
'max_inheritance_depth' => 20,
```
