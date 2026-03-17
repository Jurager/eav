---
title: Reading & Writing Attributes
weight: 40
---

# Reading & Writing Attributes

Attribute values are accessed through the `AttributeManager` via `$model->attributes()`.

## Reading Values

```php
$product = Product::find(1);

// Get a value (default locale)
$value = $product->attributes()->get('color');

// Get a value for a specific locale ID
$value = $product->attributes()->get('description', localeId: 2);
```

## Writing Values

```php
// Simple (non-localizable) value
$product->attributes()->set('color', 'red');

// Localizable value — pass array of locale translations
$product->attributes()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
]);

// Multiple values (when attribute has multiple: true)
$product->attributes()->set('tags', ['sale', 'new', 'featured']);
```

## Bulk Fill

```php
$product->attributes()->fill([
    'color'  => 'blue',
    'weight' => 1.5,
    'name'   => [
        ['locale_id' => 1, 'values' => 'T-Shirt'],
        ['locale_id' => 2, 'values' => 'Футболка'],
    ],
]);
```

## Persisting Changes

```php
$product->attributes()->save();
```

`save()` upserts all pending changes to the `entity_attribute` table in a single operation.

## Validation Errors

`fill()` validates each value against the attribute's field type and configured validation rules. If validation fails, the manager collects errors per attribute:

```php
if (! $product->attributes()->fill($input)) {
    $errors = $product->attributes()->errors(); // ['color' => ['Invalid value.'], ...]
}
```
