---
title: Reading & Writing Attributes
weight: 30
---

## Introduction

Every attributable model exposes an `eav()` accessor that returns a per-instance attribute manager. You may use this manager to read and write attribute values, persist single attributes or full sets, and batch-sync large collections.

## Reading Values

To retrieve the value of an attribute, use the `value` method:

```php
$product = Product::find(1);

$value = $product->eav()->value('color');
```

For localizable attributes, you may request a specific locale:

```php
$value = $product->eav()->value('description', localeId: 2);
```

When no locale is specified, the default locale resolved through `LocaleRegistry` is used.

## Writing a Single Value

The fluent `set` method stores a value in memory; `save` persists a single attribute by code:

```php
$product->eav()->set('color', 'red')->save('color');
```

For localizable attributes, pass an array of locale translations:

```php
$product->eav()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
])->save('description');
```

For multi-value attributes (`multiple: true`), pass an array of values:

```php
$product->eav()->set('tags', ['sale', 'new', 'featured'])->save('tags');
```

## Persisting a Full Set

To replace every stored value on the entity with a new set, use `replace`:

```php
$product->eav()->replace($fields); // persists $fields, deletes everything else
```

To add or update values without removing the rest, use `attach`:

```php
$product->eav()->attach($fields);
```

Both methods accept `array<string, Field>` — the shape returned by `validate()` below.

## Validation in Controllers

A FormRequest validates the HTTP envelope; `$model->validate()` handles the EAV-specific rules (cardinality, localization, custom field validations) and returns `array<string, Field>` ready for persistence:

```php
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

The `validate` method throws `ValidationException` on failure — Laravel renders this as `422` automatically.

## Batch Import

For bulk operations, use `AttributeManager::sync()`. It loads the schema once per unique entity type and persists every entity in chunked transactions:

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

The default chunk size is 500 entities per transaction.

### Handling Errors During Batch Import

By default, a failing chunk re-throws and halts processing. When `onError` is provided, the strategy is optimistic: the chunk is attempted in a single transaction first. If that transaction fails, each entity is retried individually. Entities that fail individually are passed to `onError` and skipped — the rest of the batch continues:

```php
AttributeManager::sync($batch, onError: function (\Throwable $e, Attributable $entity): void {
    Log::error("Sync failed for #{$entity->id}", ['error' => $e->getMessage()]);
});
```

This means a single bad entity does not abort the entire import, but it also means successfully-persisted entities within a failed chunk are re-persisted during the per-entity retry (upsert semantics, so idempotent).

## Finding Entities by Attribute Value

To look up an entity by an attribute value, use the `builder()` accessor:

```php
$manager = AttributeManager::for(Product::class);

$product  = $manager->builder()->findBy('sku', 'ABC-123');        // ?Model
$product  = $manager->builder()->findBy('price', 100.0, '<=');    // ?Model with operator

$products = $manager->builder()->findAllBy('status', 'active');   // Collection
$products = $manager->builder()->findAllBy('price', 50.0, '>=');  // Collection with operator
```

When you need to load multiple entities by a set of attribute values and index the result for O(1) lookup, use `findWhereIn()`. It returns a `Collection` keyed by the raw stored value:

```php
$byBarcode = $manager->builder()->findWhereIn('barcode', ['111', '222', '333']);

$product = $byBarcode['111']; // Product|null
```

For more advanced filtering, use the Eloquent scopes documented in [Querying](querying.md).

## Detaching Values

To remove stored values for specific attribute IDs, use `detach`:

```php
$product->eav()->detach([12, 34]);
```
