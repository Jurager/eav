---
title: Querying
weight: 60
---

# Querying

The `HasAttributes` trait adds Eloquent query scopes for filtering entities by EAV attribute values. All scopes build a `whereIn('id', subquery)` clause that targets the typed storage column resolved from the attribute's field type — no joins are added to the main query.

## Supported operators

| Operator | SQL | Notes |
|----------|-----|-------|
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
| `between` | `BETWEEN a AND b` | Pass `[$min, $max]` as value |
| `not_between` | `NOT BETWEEN a AND b` | Pass `[$min, $max]` as value |
| `tree` | NestedSet descendants | See [Tree scope](#tree-scope) |

For **localizable attributes**, the operator is applied to `entity_translations.label` instead of the typed column. All operators except `between` and `not_between` are supported for localizable attributes.

---

## Exact match

```php
Product::whereAttribute('color', 'red')->get();
```

## Custom operator

```php
Product::whereAttribute('weight', 10, '>=')->get();
Product::whereAttribute('status', 'archived', 'ne')->get();
Product::whereAttribute('ean', null, 'not_null')->get();
```

## LIKE

```php
Product::whereAttributeLike('name', 'shirt')->get();
```

## Range

```php
Product::whereAttributeBetween('price', 100, 500)->get();
```

## IN set

```php
Product::whereAttributeIn('status', ['new', 'sale'])->get();
```

## NOT IN set

```php
Product::whereAttribute('status', ['draft', 'archived'], 'nin')->get();
```

## NULL checks

```php
// Has a stored value
Product::whereAttribute('ean', null, 'not_null')->get();

// Has no stored value
Product::whereAttribute('ean', null, 'null')->get();
```

## Multiple conditions (AND)

```php
Product::whereAttributes([
    ['code' => 'color', 'value' => 'red'],
    ['code' => 'size',  'value' => 'XL'],
    ['code' => 'price', 'value' => 500, 'operator' => '<='],
])->get();
```

## Tree scope

`whereAttributeTree` finds entities whose attribute matches `$value`, then expands to all NestedSet descendants using `whereDescendantOrSelf`. Requires the model to use `NodeTrait` (e.g. `kalnoy/nestedset`). Falls back to exact-match filtering for non-NestedSet models.

```php
// Returns the matching category AND all its subcategories
Category::whereAttributeTree('code', 'electronics')->get();
```

`whereAttribute` delegates to `whereAttributeTree` automatically when operator `'tree'` is passed:

```php
Category::whereAttribute('code', 'electronics', 'tree')->get();
```

> [!NOTE]
> The tree scope executes two lightweight queries: one to resolve matching root IDs from the EAV table, one to expand the NestedSet tree. No joins are added to the original query.
