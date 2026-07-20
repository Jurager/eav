---
title: Querying
weight: 60
---

## Introduction

The `HasAttributes` trait adds Eloquent query scopes that filter entities by EAV attribute values. Every scope builds a `whereIn('id', subquery)` clause against the typed storage column — no joins are added to your main query.

For localizable attributes, the operator is applied to the `entity_translations.label` column instead of the typed value column.

String comparisons (`=`, `!=`, `in`, `nin`, `like`) are case-insensitive — `value_text` and `entity_translations.label` use `citext` on PostgreSQL and a case-insensitive collation on MySQL.

## Supported Operators

The full set of operators accepted by `whereAttribute` and the convenience scopes, resolved through [`jurager/filterable`'s `FilterOperator`](https://github.com/jurager/filterable):

| Operator | SQL |
|---|---|
| `=`, `eq` *(default)* | `= value` |
| `!=`, `ne` | `!= value` |
| `>`, `gt` | `> value` |
| `>=`, `gte` | `>= value` |
| `<`, `lt` | `< value` |
| `<=`, `lte` | `<= value` |
| `like` | `LIKE '%v%'` |
| `in` | `IN (...)` |
| `nin` | `NOT IN (...)` |
| `null` | `IS NULL` |
| `not_null` | `IS NOT NULL` |
| `between` | `BETWEEN a AND b` — pass `[$min, $max]` as the value |
| `not_between` | `NOT BETWEEN a AND b` — pass `[$min, $max]` as the value |
| `tree` | NestedSet descendants — see [Tree Scope](#tree-scope) |

Localizable attributes support every operator except `between` and `not_between`.

## Exact Match

```php
Product::whereAttribute('color', 'red')->get();
```

## Custom Operator

You may pass any of the supported operators as the third argument:

```php
Product::whereAttribute('weight', 10, '>=')->get();
Product::whereAttribute('status', 'archived', 'ne')->get();
Product::whereAttribute('ean', null, 'not_null')->get();
```

## LIKE Search

```php
Product::whereAttributeLike('name', 'shirt')->get();
```

## Range Filtering

```php
Product::whereAttributeBetween('price', 100, 500)->get();
```

## IN and NOT IN Sets

```php
Product::whereAttributeIn('status', ['new', 'sale'])->get();
Product::whereAttribute('status', ['draft', 'archived'], 'nin')->get();
```

## NULL Checks

```php
// Has a stored value
Product::whereAttribute('ean', null, 'not_null')->get();

// Has no stored value
Product::whereAttribute('ean', null, 'null')->get();
```

## Combining Multiple Conditions

To AND several conditions together, you may pass an array to `whereAttributes`:

```php
Product::whereAttributes([
    ['code' => 'color', 'value' => 'red'],
    ['code' => 'size',  'value' => 'XL'],
    ['code' => 'price', 'value' => 500, 'operator' => '<='],
])->get();
```

## Tree Scope

The `whereAttributeTree` scope finds entities whose attribute matches `$value`, then expands the result to every NestedSet descendant via `whereDescendantOrSelf`. This requires the model to use `NodeTrait` (for example, from `kalnoy/nestedset`); on non-NestedSet models it falls back to exact-match filtering.

```php
// Returns the matching category AND all its subcategories
Category::whereAttributeTree('code', 'electronics')->get();
```

`whereAttribute` delegates to `whereAttributeTree` automatically when you pass the `tree` operator:

```php
Category::whereAttribute('code', 'electronics', 'tree')->get();
```

The tree scope runs two lightweight queries — one to resolve matching root IDs from the EAV table, one to expand the NestedSet tree.
