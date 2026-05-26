---
title: Managing Schema
weight: 50
---

## Introduction

The `SchemaManager` is the entry point for creating, updating, deleting, and sorting attribute definitions, groups, and enum values. You may resolve it from the container wherever you need it:

```php
use Jurager\Eav\Managers\SchemaManager;

$schema = app(SchemaManager::class);
```

`SchemaManager` manages the *schema* — what attributes exist and how they are configured. For reading and writing attribute *values* on entity instances, use the `eav()` accessor described in [Reading & Writing Attributes](attributes.md).

## Managing Attributes

`SchemaManager` delegates attribute operations to `AttributeSchema`, accessed via `$schema->attribute()`.

### Creating an Attribute

```php
$attribute = $schema->attribute()->create([
    'entity_type'        => 'product',
    'attribute_type_id'  => 1,
    'attribute_group_id' => 2,
    'code'               => 'color',
    'mandatory'          => false,
    'localizable'        => true,
    'translations'       => [
        ['locale_id' => 1, 'label' => 'Color'],
        ['locale_id' => 2, 'label' => 'Цвет'],
    ],
]);
```

The `sort` value is auto-positioned at the end of the group when omitted. Flag constraints are enforced by the attribute type — unsupported flags are silently forced to `false`. An `AttributeCreated` event is dispatched after a successful create.

### Updating an Attribute

```php
$schema->attribute()->update($attribute, [
    'code'         => 'base_color',
    'localizable'  => false,
    'translations' => [
        ['locale_id' => 1, 'label' => 'Base Color'],
    ],
]);
```

Type constraints are re-evaluated on every update. An `AttributeUpdated` event is dispatched on success.

### Deleting an Attribute

```php
$schema->attribute()->delete($attribute);
```

An `AttributeDeleted` event is dispatched with a pre-deletion snapshot of the attribute.

### Sorting Attributes

To move an attribute to a specific position within its group, call `sort` with a zero-based index. Siblings are renumbered automatically:

```php
$schema->attribute()->sort($attribute, position: 0); // move to top
```

### Finding an Attribute

```php
$attribute = $schema->attribute()->find(42); // throws ModelNotFoundException if missing
```

### Find or Create

To look up an attribute by entity type and code, or create it when it doesn't exist:

```php
$attribute = $schema->attribute()->findOrCreate('product', 'color', [
    'attribute_type_id'  => 1,
    'attribute_group_id' => 2,
    'translations'       => [
        ['locale_id' => 1, 'label' => 'Color'],
    ],
]);
```

For existing attributes only translations are updated — other fields are not overwritten.

## Managing Groups

Groups organize attributes for display. Operations are accessed via `$schema->group()`:

```php
$group = $schema->group()->create([
    'code'         => 'dimensions',
    'translations' => [
        ['locale_id' => 1, 'label' => 'Dimensions'],
        ['locale_id' => 2, 'label' => 'Размеры'],
    ],
]);

$schema->group()->update($group, ['code' => 'measurements']);
$schema->group()->delete($group);
$schema->group()->sort($group, position: 1);
$schema->group()->find(3);
```

To assign existing attributes to a group by ID without affecting other rows:

```php
$schema->group()->attach($group, attributeIds: [4, 7, 12]);
```

## Managing Enum Values

For `select`-typed attributes, manage the available options via `$schema->enum()`:

```php
$enum = $schema->enum()->create($attribute, [
    'code'         => 'red',
    'translations' => [
        ['locale_id' => 1, 'label' => 'Red'],
        ['locale_id' => 2, 'label' => 'Красный'],
    ],
]);

$schema->enum()->update($enum, ['code' => 'crimson']);
$schema->enum()->delete($enum);
$schema->enum()->find(18);
```

## Querying the Schema

Every query method accepts an optional `callable` modifier. Without a modifier, the method returns a `Collection`. You may pass a modifier to apply scopes, sorting, or pagination:

```php
$attributes = $schema->attributes(); // Collection

$paginated = $schema->attributes(
    fn ($q) => $q->where('entity_type', 'product')->paginate(15)
);

$enums  = $schema->enums($attribute, fn ($q) => $q->orderBy('sort')->get());
$types  = $schema->types();
$groups = $schema->groups(fn ($q) => $q->paginate(15));
```

## Full-Text Search Over Attributes

When [Laravel Scout](https://laravel.com/docs/scout) is configured on the `Attribute` model, you may search by code and translated labels:

```php
use Jurager\Eav\Exceptions\SearchNotAvailableException;

try {
    $results = $schema->search('color', fn ($b) => $b->paginate(15));
} catch (SearchNotAvailableException $e) {
    // Scout is not configured on the attribute model
}
```

## Batch Creating Attributes

To create many attributes at once — for example during an import — use the batch API. Existing attributes (matched by `entity_type` + `code`) are skipped; only missing ones are inserted:

```php
$created = $schema->attribute()->batch()->execute([
    [
        'entity_type'        => 'product',
        'attribute_type_id'  => 1,
        'code'               => 'color',
        'translations'       => [['locale_id' => 1, 'label' => 'Color']],
    ],
    [
        'entity_type'        => 'product',
        'attribute_type_id'  => 2,
        'code'               => 'weight',
        'translations'       => [['locale_id' => 1, 'label' => 'Weight']],
    ],
], fireEvents: false);
```

Pass `fireEvents: false` to suppress `AttributeCreated` events during large imports.
