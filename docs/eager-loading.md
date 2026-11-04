---
title: Loading attribute values
weight: 36
---

Use the `attribute_values` relation to eager load stored values.

## Loading stored values

```php
$product->load('attribute_values');
$product->attribute_values; // Collection<EntityAttribute>
```

## Reading resolved values

Use `values()` to retrieve rows with resolved field values:

```php
$product->eav()->values();                    // Collection — all attributes
$product->eav()->values(['color', 'weight']); // Collection — specific codes only
$product->eav()->values(paginated: 15);       // LengthAwarePaginator

// Apply request filters.
$product->eav()->values(query: fn (Builder $query) => $query->filter($request->input('filter', [])));
```

Pagination and custom constraints query the database.

Eager load related data before rendering a collection:

```php
$products->load([
    'attribute_values' => fn ($q) => $q->with([
        'attribute.type',
        'attribute.group.translations',
        'attribute.translations',
        'attribute.enums.translations',
        'translations',
    ]),
]);

foreach ($products as $product) {
    $values = $product->eav()->values(); // no DB queries
}
```
