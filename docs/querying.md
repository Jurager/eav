---
title: Querying
weight: 60
---

# Querying

The `HasAttributes` trait adds Eloquent query scopes for filtering entities by EAV values.

## Exact match

```php
Product::whereAttribute('color', 'red')->get();
```

## Custom operator

```php
Product::whereAttribute('weight', 10, '>=')->get();
```

## LIKE

```php
Product::whereAttributeLike('name', '%shirt%')->get();
```

## Range

```php
Product::whereAttributeBetween('price', 100, 500)->get();
```

## IN set

```php
Product::whereAttributeIn('status', ['new', 'sale'])->get();
```

## Multiple conditions

```php
Product::whereAttributes([
    ['code' => 'color', 'value' => 'red'],
    ['code' => 'size',  'value' => 'XL'],
    ['code' => 'price', 'value' => 500, 'operator' => '<='],
])->get();
```

> [!NOTE]
> All conditions are AND-combined. Each scope adds a `whereIn('id', subquery)` clause targeting the typed storage column resolved from the attribute's field type.
