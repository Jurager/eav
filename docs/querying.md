---
title: Querying by Attributes
weight: 50
---

# Querying by Attributes

The `HasAttributes` trait adds Eloquent query scopes for filtering entities by their EAV values.

## Exact Match

```php
Product::whereAttribute('color', 'red')->get();
```

## Custom Operator

```php
Product::whereAttribute('weight', 10, '>=')->get();
```

## LIKE Search

```php
Product::whereAttributeLike('name', '%shirt%')->get();
```

## Range

```php
Product::whereAttributeBetween('price', 100, 500)->get();
```

## IN Set

```php
Product::whereAttributeIn('status', ['new', 'sale'])->get();
```

## Multiple Conditions (AND)

```php
Product::whereAttributes([
    ['code' => 'color', 'value' => 'red'],
    ['code' => 'size',  'value' => 'XL'],
    ['code' => 'price', 'value' => 500, 'operator' => '<='],
])->get();
```

> [!NOTE]
> All attribute scopes are AND-combined. Each scope adds a `whereIn('id', subquery)` clause targeting the typed storage column resolved by the attribute's field type.
