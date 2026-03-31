---
title: Reading & Writing Attributes
weight: 30
---

# Reading & Writing Attributes

Attribute values are accessed through `$model->attributes()`.

## Reading

```php
$product = Product::find(1);

$value = $product->attributes()->value('color');

// Specific locale
$value = $product->attributes()->value('description', localeId: 2);
```

## Writing a Single Value

Set in memory, then persist:

```php
// Simple value
$product->attributes()->set('color', 'red')->save('color');

// Localizable value
$product->attributes()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
])->save('description');

// Multiple values (attribute must have multiple: true)
$product->attributes()->set('tags', ['sale', 'new', 'featured'])->save('tags');
```

`set()` is chainable. `save(string $code)` persists a single attribute by code.

## Syncing a Full Set

Replace all existing values for the entity with a new set:

```php
$product->attributes()->replace($fields); // persists $fields, deletes all others
```

Persist without removing existing values:

```php
$product->attributes()->attach($fields);
```

Both methods accept `array<string, Field>` as returned by `validate()`.

## Validated Fill

`validate()` on the model validates the input and returns `array<string, Field>` ready for persistence:

```php
$fields = $product->validate([
    ['code' => 'color',  'values' => 'red'],
    ['code' => 'weight', 'values' => 1.5],
]);

$product->attributes()->attach($fields);
```

Throws `ValidationException` on failure. Errors are keyed by attribute code.

## Batch Import

For bulk operations, `AttributeManager::sync()` loads the schema once per unique `(entity_type, params)` combination and persists all entities in chunked transactions:

```php
use Jurager\Eav\Managers\AttributeManager;

AttributeManager::sync(collect([
    ['entity' => $product1, 'data' => ['color' => 'red',  'weight' => 1.5]],
    ['entity' => $product2, 'data' => ['color' => 'blue', 'weight' => 2.0]],
]));
```

When all entities share the same schema, build it once to avoid repeated lookups:

```php
$schema = AttributeManager::schema(Product::first());

AttributeManager::sync($batch, prebuiltSchema: $schema, chunkSize: 200);
```

Default chunk size is 500. Each chunk runs in a single transaction (~7 queries regardless of entity or attribute count).

### Error handling

By default a failing entity re-throws and stops the batch. Pass `$onError` to handle failures per-entity and continue:

```php
AttributeManager::sync($batch, onError: function (\Throwable $e, Attributable $entity): void {
    $entity->delete();
    Log::error("Sync failed for #{$entity->id}", ['error' => $e->getMessage()]);
});
```

The failed entity's transaction is rolled back; all others are unaffected.

## Detaching

Remove stored values for specific attribute IDs:

```php
$product->attributes()->detach([12, 34]);
```
