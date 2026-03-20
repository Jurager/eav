---
title: Attribute Inheritance
weight: 90
---

# Attribute Inheritance

Entities in a hierarchy can inherit the attribute schema of their ancestors. A typical example is a category tree where a subcategory shows all attributes from its parent categories.

## Setup

`shouldInheritAttributes()` is part of the `Attributable` contract and has a default implementation in `HasAttributes` that returns `false`. Override it when you need inheritance:

```php
class Category extends Model implements Attributable
{
    use HasAttributes, NodeTrait; // NodeTrait provides nested-set support

    public function shouldInheritAttributes(): bool
    {
        return $this->is_inherits_properties && $this->parent_id;
    }
}
```

When `getAttributeScope()` returns `byRelation`, the `AttributeInheritanceResolver` is called automatically to expand the set of related entities with their ancestors.

## How It Works

The `AttributeInheritanceResolver` detects the tree strategy from the model:

- **Nested set** (`_lft`/`_rgt` columns, e.g. via `kalnoy/nestedset`) — resolves all ancestors in a single query using bounds comparison.
- **Parent ID chain** — walks `parent_id` level by level in batched queries (max 10 levels deep).

Inheritance stops at the first ancestor that returns `false` from `shouldInheritAttributes()`.

## Example

Given a category tree:

```
Root (inherits: false)
└── Electronics (inherits: true)
    └── Phones (inherits: true)
```

A product assigned to `Phones` will have access to attributes defined on both `Phones` and `Electronics`. Attributes from `Root` are excluded because `Root::shouldInheritAttributes()` returns `false`.
