---
title: Querying attribute values
weight: 60
---

The `HasAttributes` trait adds query scopes for filtering entities by attribute values.

String comparisons are case-insensitive.

## Supported operators

`whereAttribute()` supports these operators:

| Operator | SQL |
|---|---|
| `=`, `eq` *(default)* | `= value` |
| `!=`, `ne` | `!= value` |
| `>`, `gt` | `> value` |
| `>=`, `gte` | `>= value` |
| `<`, `lt` | `< value` |
| `<=`, `lte` | `<= value` |
| `like` | Case-insensitive substring |
| `in` | `IN (...)` |
| `nin` | `NOT IN (...)` |
| `null` | `IS NULL` |
| `not_null` | `IS NOT NULL` |
| `between` | `BETWEEN a AND b` — pass `[$min, $max]` as the value |
| `not_between` | `NOT BETWEEN a AND b` — pass `[$min, $max]` as the value |
| `tree` | NestedSet descendants — see [Tree Scope](#tree-scope) |

Localizable attributes support every operator except `between` and `not_between`.

## Exact match

```php
Product::whereAttribute('color', 'red')->get();
```

## Custom operator

You may pass any of the supported operators as the third argument:

```php
Product::whereAttribute('weight', 10, '>=')->get();
Product::whereAttribute('status', 'archived', 'ne')->get();
Product::whereAttribute('ean', null, 'not_null')->get();
```

## LIKE search

```php
Product::whereAttributeLike('name', 'shirt')->get();
```

The search matches a substring, case-insensitively. `%` and `_` in the search term are matched literally, not as wildcards.

SQLite comparisons fold case for ASCII letters only.

## Range filtering

```php
Product::whereAttributeBetween('price', 100, 500)->get();
```

## IN and NOT IN sets

```php
Product::whereAttributeIn('status', ['new', 'sale'])->get();
Product::whereAttribute('status', ['draft', 'archived'], 'nin')->get();
```

## NULL checks

```php
// Has a stored value
Product::whereAttribute('ean', null, 'not_null')->get();

// Has no stored value
Product::whereAttribute('ean', null, 'null')->get();
```

## Combining multiple conditions

To AND several conditions together, you may pass an array to `whereAttributes`:

```php
Product::whereAttributes([
    ['code' => 'color', 'value' => 'red'],
    ['code' => 'size',  'value' => 'XL'],
    ['code' => 'price', 'value' => 500, 'operator' => '<='],
])->get();
```

## Tree scope

`whereAttributeTree()` includes matching entities and their descendants. Implement `ShouldUseNestedSet` with `NodeTrait` to enable tree expansion; other models use exact matching.

```php
// Returns the matching category AND all its subcategories
Category::whereAttributeTree('code', 'electronics')->get();
```

`whereAttribute` delegates to `whereAttributeTree` automatically when you pass the `tree` operator:

```php
Category::whereAttribute('code', 'electronics', 'tree')->get();
```

## Finding entities by attribute value

Use `Attributes::query()` to find entities by attribute value:

```php
use Jurager\Eav\Facades\Attributes;

$product  = Attributes::query(Product::class)->findBy('sku', 'ABC-123');      // ?Model
$product  = Attributes::query(Product::class)->findBy('price', 100.0, '<=');  // ?Model with operator

$products = Attributes::query(Product::class)->findAllBy('status', 'active'); // Collection
$products = Attributes::query('product')->findAllBy('price', 50.0, '>=');     // by morph alias
```

`findBy()` and `findAllBy()` are shortcuts for the query scopes — `Product::whereAttribute('sku', 'ABC-123')->first()` is the same lookup.

Use `findWhereIn()` to return matching entities keyed by stored value:

```php
$byBarcode = Attributes::query(Product::class)->findWhereIn('barcode', ['111', '222', '333']);

$product = $byBarcode->get('111'); // Product|null
```

For more advanced filtering, use the Eloquent scopes documented in [Querying](querying.md).
