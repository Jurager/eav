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

### Creating an Attribute

To create a new attribute, you may pass the attribute payload to `createAttribute`. Translations are persisted automatically when included in the data:

```php
$attribute = $schema->createAttribute([
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
$schema->updateAttribute($attribute, [
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
$schema->deleteAttribute($attribute);
```

An `AttributeDeleted` event is dispatched with a pre-deletion snapshot of the attribute, so listeners can act on the data that's about to disappear.

### Sorting Attributes

To move an attribute to a specific position within its group, you may call `sortAttribute` with a zero-based index. Siblings are renumbered automatically:

```php
$schema->sortAttribute($attribute, position: 0); // move to top
```

## Managing Groups

Groups organize attributes for display. The same CRUD pattern applies:

```php
$group = $schema->createGroup([
    'code'         => 'dimensions',
    'translations' => [
        ['locale_id' => 1, 'label' => 'Dimensions'],
        ['locale_id' => 2, 'label' => 'Размеры'],
    ],
]);

$schema->updateGroup($group, ['code' => 'measurements']);
$schema->deleteGroup($group);
$schema->sortGroup($group, position: 1);
```

To attach existing attributes to a group by ID without affecting any other rows:

```php
$schema->attachAttributesToGroup($group, attributeIds: [4, 7, 12]);
```

## Managing Enum Values

For `select`-typed attributes, you may manage the available options through enum methods:

```php
$enum = $schema->createEnum($attribute, [
    'code'         => 'red',
    'translations' => [
        ['locale_id' => 1, 'label' => 'Red'],
        ['locale_id' => 2, 'label' => 'Красный'],
    ],
]);

$schema->updateEnum($enum, ['code' => 'crimson']);
$schema->deleteEnum($enum);
```

## Querying the Schema

Every query method accepts an optional `callable` modifier. Without a modifier, the method returns a `Collection`. You may pass a modifier to apply scopes, sorting, or pagination:

```php
$attributes = $schema->getAttributes(); // Collection

$paginated = $schema->getAttributes(
    fn ($q) => $q->where('entity_type', 'product')->paginate(15)
);

$enums  = $schema->getEnums($attribute, fn ($q) => $q->orderBy('sort')->get());
$types  = $schema->getTypes();
$groups = $schema->getGroups(fn ($q) => $q->paginate(15));
```

## Finding a Record by ID

The `get*` methods look up a single record and throw `ModelNotFoundException` when nothing matches:

```php
$schema->getAttribute(42);
$schema->getGroup(3);
$schema->getEnum(18);
$schema->getType(1);
```

## Full-Text Search Over Attributes

When [Laravel Scout](https://laravel.com/docs/scout) is configured on the `Attribute` model, you may search by code and translated labels through `searchAttributes`:

```php
use Jurager\Eav\Exceptions\SearchNotAvailableException;

try {
    $results = $schema->searchAttributes('color', fn ($b) => $b->paginate(15));
} catch (SearchNotAvailableException $e) {
    // Scout is not configured on the attribute model
}
```
