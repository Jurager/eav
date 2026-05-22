---
title: Querying
weight: 60
---

## Introduction

The `HasAttributes` trait adds Eloquent query scopes that filter entities by EAV attribute values. Every scope builds a `whereIn('id', subquery)` clause that targets the typed storage column resolved from the attribute's field type, so no joins are added to your main query. This keeps the SQL plan stable regardless of how many EAV conditions you stack.

For localizable attributes, the operator is applied to the `entity_translations.label` column instead of the typed value column.

## Supported Operators

The full set of operators accepted by `whereAttribute` and the convenience scopes:

| Operator | SQL | Notes |
|---|---|---|
| `=` *(default)* | `= value` | Case-insensitive for strings via `LOWER()` |
| `!=`, `ne` | `!= value` | Case-insensitive for strings |
| `>` | `> value` | |
| `>=` | `>= value` | |
| `<` | `< value` | |
| `<=` | `<= value` | |
| `like` | `LOWER(col) LIKE '%v%'` | Case-insensitive, both sides lowercased |
| `in` | `IN (...)` | Case-insensitive for strings |
| `nin`, `not_in` | `NOT IN (...)` | Case-insensitive for strings |
| `null` | `IS NULL` | |
| `not_null` | `IS NOT NULL` | |
| `between` | `BETWEEN a AND b` | Pass `[$min, $max]` as the value |
| `not_between` | `NOT BETWEEN a AND b` | Pass `[$min, $max]` as the value |
| `tree` | NestedSet descendants | See [Tree Scope](#tree-scope) |

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

The tree scope runs two lightweight queries — one to resolve matching root IDs from the EAV table, one to expand the NestedSet tree. No joins are added to the original query.
