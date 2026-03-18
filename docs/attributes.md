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
$value = $product->attributes()->value('color');

// Get a value for a specific locale ID
$value = $product->attributes()->value('description', localeId: 2);
```

## Writing a Single Value

Set a value in memory, then persist it:

```php
// Simple (non-localizable) value
$product->attributes()->set('color', 'red')->save('color');

// Localizable value — pass array of locale translations
$product->attributes()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
])->save('description');

// Multiple values (when attribute has multiple: true)
$product->attributes()->set('tags', ['sale', 'new', 'featured'])->save('tags');
```

`set()` returns `$this` for chaining. `save(string $code)` persists a single attribute code.

## Syncing All Values

To persist a full set of attribute values and remove any others:

```php
// Build and fill Field instances, then sync
$fields = [/* array of Field instances keyed by code */];

$product->attributes()->sync($fields);
```

To persist fields without removing existing ones:

```php
$product->attributes()->attach($fields);
```

## Validated Fill

Call `validate()` directly on the model. The method is provided by the `HasAttributes` trait
and delegates to `AttributeValidator` internally:

```php
$fields = $product->validate([
    ['code' => 'color',  'values' => 'red'],
    ['code' => 'weight', 'values' => 1.5],
]);

// $fields is array<string, Field> — pass to attach() or sync()
$product->attributes()->attach($fields);
```

`validate()` throws `ValidationException` if any field fails. Errors are keyed by attribute code.

If you need to reuse an existing `AttributeManager` instance (e.g. inside a service that already
holds one), you can still call `AttributeValidator` directly:

```php
use Jurager\Eav\AttributeValidator;

$fields = AttributeValidator::make($product, $manager)->validate($input);
```

## Batch Import

For bulk imports use `AttributeManager::syncBatch()`. The schema is loaded once per unique
`(entity_type, params)` combination and reused across all chunks:

```php
use Jurager\Eav\AttributeManager;

AttributeManager::syncBatch(collect([
    ['entity' => $product1, 'data' => ['color' => 'red',  'weight' => 1.5]],
    ['entity' => $product2, 'data' => ['color' => 'blue', 'weight' => 2.0]],
    // …
]));

// Custom chunk size (default 500)
AttributeManager::syncBatch($batch, chunkSize: 200);
```

Each chunk is flushed in ~7 DB queries, regardless of entity or attribute count.

## Detaching Attributes

Remove stored values for specific attribute IDs:

```php
$product->attributes()->detach([12, 34]);
```
