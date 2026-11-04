---
title: Importing attribute values
weight: 35
---

You can write values for several entities with `Attributes::sync()`:

```php
use Jurager\Eav\Facades\Attributes;

Attributes::sync(collect([
    ['entity' => $shirt, 'data' => ['color' => 'blue', 'weight' => 0.3]],
    ['entity' => $jacket, 'data' => ['color' => 'black', 'weight' => 1.2]],
]));
```

The import processes 500 entities per transaction by default. When an entity appears more than once in a batch, its last values win. Search updates run after commit.

## Reusing a schema

If every entity uses the same attributes, pass a shared schema:

```php
$schema = Attributes::schema(Product::class);

Attributes::sync($batch, schema: $schema, chunkSize: 200);
```

`schema()` accepts a model class, morph alias, or an entity. Pass an entity when its attributes depend on a [scope](inheritance.md).

You can also prepare fields for a single entity:

```php
$fields = $schema->fill(['color' => 'blue']);

$product->eav()->attach($fields->all());
```

## Handling failed entities

A failed chunk stops the import. Pass `onError` to retry its entities individually, report failures, and continue:

```php
use Jurager\Eav\Contracts\Attributable;

Attributes::sync($batch, onError: function (Throwable $exception, Attributable $entity): void {
    report($exception);
});
```
