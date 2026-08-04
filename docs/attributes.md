---
title: Reading & Writing Attributes
weight: 30
---

## Introduction

Every attributable model exposes an `eav()` accessor for reading and writing attribute values, persisting single attributes or full sets, and batch-syncing large collections. The manager it returns is cached on the entity itself, so repeated calls within a request share state.

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

`set` always replaces the whole list. To append instead — without first reading the existing values back — use `add`, with either a single value or an array:

```php
$product->eav()->add('tags', 'featured')->save('tags');
$product->eav()->add('tags', ['sale', 'new'])->save('tags');
```

On a non-multiple field, `add` behaves exactly like `set` — there is only one slot to fill.

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

For bulk operations spanning many entities, use the `Attributes` facade instead of `eav()` — there's no single entity to cache a manager on. `Attributes::sync()` loads the schema once per unique entity type and persists every entity in chunked transactions:

```php
use Jurager\Eav\Facades\Attributes;

Attributes::sync(collect([
    ['entity' => $product1, 'data' => ['color' => 'red',  'weight' => 1.5]],
    ['entity' => $product2, 'data' => ['color' => 'blue', 'weight' => 2.0]],
]));
```

When every entity in the batch shares the same schema, you may build it once and pass it in to avoid repeated lookups:

```php
$schema = Attributes::schema(Product::first());

Attributes::sync($batch, prebuiltSchema: $schema, chunkSize: 200);
```

The default chunk size is 500 entities per transaction.

### Handling Errors During Batch Import

By default, a failing chunk re-throws and halts processing. Pass `onError` to retry a failed chunk entity-by-entity instead — bad entities are skipped and passed to the callback, and the rest of the batch continues:

```php
Attributes::sync($batch, onError: function (\Throwable $e, Attributable $entity): void {
    Log::error("Sync failed for #{$entity->id}", ['error' => $e->getMessage()]);
});
```

Persistence is upsert-based, so entities already saved before a chunk failed are retried safely.

## Finding Entities by Attribute Value

To look up an entity by an attribute value, there's no entity to call `eav()` on yet — pass the entity type instead, an FQCN implementing `Attributable` or its morph-map key, to `Attributes::for()`, and use the `builder()` accessor:

```php
use Jurager\Eav\Facades\Attributes;

$manager = Attributes::for(Product::class);

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
