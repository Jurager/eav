---
title: Reading & Writing Attributes
weight: 30
---

# Reading & Writing Attributes

Attribute values are accessed through `$model->eav()`.

## Reading

```php
$product = Product::find(1);

$value = $product->eav()->value('color');

// Specific locale
$value = $product->eav()->value('description', localeId: 2);
```

## Writing a Single Value

Set in memory, then persist:

```php
// Simple value
$product->eav()->set('color', 'red')->save('color');

// Localizable value
$product->eav()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
])->save('description');

// Multiple values (attribute must have multiple: true)
$product->eav()->set('tags', ['sale', 'new', 'featured'])->save('tags');
```

`set()` is chainable. `save(string $code)` persists a single attribute by code.

## Syncing a Full Set

Replace all existing values for the entity with a new set:

```php
$product->eav()->replace($fields); // persists $fields, deletes all others
```

Persist without removing existing values:

```php
$product->eav()->attach($fields);
```

Both methods accept `array<string, Field>` as returned by `validate()`.

## Validation in Controllers

The FormRequest validates the HTTP envelope; `$model->validate()` handles EAV-specific rules and returns `array<string, Field>` ready for persistence:

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

`validate()` throws `ValidationException` on failure — Laravel renders it as `422` automatically. Errors are keyed by attribute code.

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

## Find by Attribute Value

Find entity instances directly via the manager without building an Eloquent query:

```php
$manager = AttributeManager::for(Product::class);

$product = $manager->findBy('sku', 'ABC-123');               // ?Model
$product = $manager->findBy('price', 100.0, '<=');          // ?Model with operator

$products = $manager->findAllBy('status', 'active');         // Collection
$products = $manager->findAllBy('price', 50.0, '>=');       // Collection with operator
```

## Detaching

Remove stored values for specific attribute IDs:

```php
$product->eav()->detach([12, 34]);
```
