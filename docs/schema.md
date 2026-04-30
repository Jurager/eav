---
title: Managing Schema
weight: 50
---

# Managing Schema

`SchemaManager` is the entry point for creating, updating, deleting, and sorting attribute definitions, groups, and enum values.

```php
use Jurager\Eav\Managers\SchemaManager;

$schema = app(SchemaManager::class);
```

> [!NOTE]
> `SchemaManager` manages the *schema* — what attributes exist and how they are configured. For reading and writing attribute *values* on entity instances, use `$model->eav()`.

## Attributes

### Create

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

- `sort` is auto-positioned at the end of the group when omitted.
- Translations are persisted automatically when `translations` is present.
- Flag constraints are enforced by the attribute type (unsupported flags are forced to `false`).
- Dispatches `AttributeCreated`.

### Update

```php
$schema->updateAttribute($attribute, [
    'code'        => 'base_color',
    'localizable' => false,
    'translations' => [
        ['locale_id' => 1, 'label' => 'Base Color'],
    ],
]);
```

Type constraints are re-evaluated on every update. Dispatches `AttributeUpdated`.

### Delete

```php
$schema->deleteAttribute($attribute); // dispatches AttributeDeleted (pre-deletion snapshot)
```

### Sort

Move to a zero-based position within the group. Siblings are renumbered automatically:

```php
$schema->sortAttribute($attribute, position: 0); // move to top
```

## Groups

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

// Attach attributes by ID (existing rows unaffected)
$schema->attachAttributesToGroup($group, attributeIds: [4, 7, 12]);
```

## Enum Values

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

## Querying

All query methods accept an optional `callable` modifier. Without a modifier they return a `Collection`. Pass a modifier to apply scopes, sorting, or pagination:

```php
$attributes = $schema->getAttributes(); // Collection

$paginated = $schema->getAttributes(
    fn ($q) => $q->where('entity_type', 'product')->paginate(15)
);

$enums  = $schema->getEnums($attribute, fn ($q) => $q->orderBy('sort')->get());
$types  = $schema->getTypes();
$groups = $schema->getGroups(fn ($q) => $q->paginate(15));
```

## Find by ID

```php
$schema->getAttribute(42); // throws ModelNotFoundException if not found
$schema->getGroup(3);
$schema->getEnum(18);
$schema->getType(1);
```

## Full-text Search

Requires [Laravel Scout](https://laravel.com/docs/scout) on the `Attribute` model:

```php
use Jurager\Eav\Exceptions\SearchNotAvailableException;

try {
    $results = $schema->searchAttributes('color', fn ($b) => $b->paginate(15));
} catch (SearchNotAvailableException $e) {
    // Scout not configured on the attribute model
}
```
