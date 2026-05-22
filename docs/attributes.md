---
title: Reading & Writing Attributes
weight: 30
---

## Introduction

Every attributable model exposes an `eav()` accessor that returns a per-instance attribute manager. You may use this manager to read and write attribute values, validate input, persist single attributes or full sets, and batch-sync large collections.

## Reading Values

To retrieve the value of an attribute, you may use the `value` method:

```php
$product = Product::find(1);

$value = $product->eav()->value('color');
```

For localizable attributes, you may request a specific locale:

```php
$value = $product->eav()->value('description', localeId: 2);
```

When no locale is specified, the default locale (resolved through `LocaleRegistry`) is used.

## Writing a Single Value

The fluent `set` method stores a value in memory; `save` persists a single attribute by code:

```php
// Simple value
$product->eav()->set('color', 'red')->save('color');

// Localizable value
$product->eav()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
])->save('description');

// Multiple values (the attribute must have multiple: true)
$product->eav()->set('tags', ['sale', 'new', 'featured'])->save('tags');
```

The `set` method is chainable, so you may stage several values before persisting them.

## Persisting a Full Set

To replace every stored value on the entity with a new set, you may use `replace`:

```php
$product->eav()->replace($fields); // persists $fields, deletes everything else
```

To add or update values without removing the rest, use `attach`:

```php
$product->eav()->attach($fields);
```

Both methods accept `array<string, Field>` — the exact shape returned by `validate()` below.

## Validation in Controllers

A FormRequest validates the HTTP envelope; `$model->validate()` handles the EAV-specific rules (cardinality, localization, custom field validations) and returns `array<string, Field>` ready for persistence:

```php
// PATCH /products/{product}/attributes
// {
//   "attributes": [
//     { "code": "color",  "values": "red" },
//     { "code": "weight", "values": 1.5 },
//     { "code": "tags",   "values": ["sale", "new"] }
//   ]
// }

class AttachProductAttributeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'attributes' => ['required', 'array', 'min:1'],
        ];
    }
}

class ProductController extends Controller
{
    public function attachAttributes(AttachProductAttributeRequest $request, Product $product): Response
    {
        $fields = $product->validate($request->validated()['attributes']);

        $product->eav()->attach($fields);

        return response('', 204);
    }
}
```

The `validate` method throws `ValidationException` on failure — Laravel renders this as `422` automatically. Errors are keyed by attribute code, so frontend forms can map them back to specific inputs.

## Batch Import

For bulk operations, you may use `AttributeManager::sync()`. It loads the schema once per unique `(entity_type, params)` combination and persists every entity in chunked transactions:

```php
use Jurager\Eav\Managers\AttributeManager;

AttributeManager::sync(collect([
    ['entity' => $product1, 'data' => ['color' => 'red',  'weight' => 1.5]],
    ['entity' => $product2, 'data' => ['color' => 'blue', 'weight' => 2.0]],
]));
```

When every entity in the batch shares the same schema, you may build it once and pass it in to avoid repeated lookups:

```php
$schema = AttributeManager::schema(Product::first());

AttributeManager::sync($batch, prebuiltSchema: $schema, chunkSize: 200);
```

The default chunk size is 500 entities per transaction. Each chunk runs as a single transaction with roughly seven queries, regardless of how many entities or attributes are involved.

### Handling Errors During Batch Import

By default, a failing entity re-throws and stops the batch. To handle failures per-entity and continue, you may pass a callback as the `onError` parameter:

```php
AttributeManager::sync($batch, onError: function (\Throwable $e, Attributable $entity): void {
    $entity->delete();
    Log::error("Sync failed for #{$entity->id}", ['error' => $e->getMessage()]);
});
```

The failed entity's transaction is rolled back; all other entities in the batch are unaffected.

## Finding Entities by Attribute Value

To look up an entity directly by an attribute value without writing an Eloquent query yourself, you may use the manager's `findBy` and `findAllBy` methods:

```php
$manager = AttributeManager::for(Product::class);

$product = $manager->findBy('sku', 'ABC-123');               // ?Model
$product = $manager->findBy('price', 100.0, '<=');           // ?Model with operator

$products = $manager->findAllBy('status', 'active');         // Collection
$products = $manager->findAllBy('price', 50.0, '>=');        // Collection with operator
```

For more advanced filtering you should use the Eloquent scopes documented in [Querying](querying.md).

## Detaching Values

To remove stored values for specific attribute IDs, you may use the `detach` method:

```php
$product->eav()->detach([12, 34]);
```
