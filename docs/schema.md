---
title: Managing Attribute Schema
weight: 45
---

# Managing Attribute Schema

`AttributeSchemaManager` is the primary entry point for creating, updating, deleting, and sorting attribute definitions, groups, and enum values. It is registered as a singleton and resolved via the service container.

```php
use Jurager\Eav\Managers\SchemaManager;

$schema = app(SchemaManager::class);
```

> [!NOTE]
> `AttributeSchemaManager` manages the *schema* — what attributes exist and how they are configured. For reading and writing attribute *values* on entity instances, use `AttributeManager` via `$model->attributes()`.

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

**Automatic behaviours:**
- Type capability flags (`localizable`, `multiple`, `unique`, `filterable`, `searchable`) are forced to `false` if the attribute type does not support them.
- `sort` is auto-positioned at the end of the group if not provided.
- Translations are persisted automatically when `translations` is present in the data.
- `AttributeCreated` event is dispatched.

### Update

```php
$schema->updateAttribute($attribute, [
    'code'         => 'base_color',
    'localizable'  => false,
    'translations' => [
        ['locale_id' => 1, 'label' => 'Base Color'],
    ],
]);
```

Type constraints are re-evaluated on every update. `AttributeUpdated` event is dispatched.

### Delete

```php
$schema->deleteAttribute($attribute);
```

`AttributeDeleted` event is dispatched with a snapshot of the attribute before deletion.

### Sort

Move an attribute to a new zero-based position within its group. All siblings are renumbered:

```php
$schema->sortAttribute($attribute, position: 0); // move to top
```

## Attribute Groups

### Create

```php
$group = $schema->createGroup([
    'code'         => 'dimensions',
    'translations' => [
        ['locale_id' => 1, 'label' => 'Dimensions'],
        ['locale_id' => 2, 'label' => 'Размеры'],
    ],
]);
```

`sort` is auto-positioned at the end. `AttributeGroupCreated` event is dispatched.

### Update / Delete / Sort

```php
$schema->updateGroup($group, ['code' => 'measurements']);

$schema->deleteGroup($group); // dispatches AttributeGroupDeleted

$schema->sortGroup($group, position: 1);
```

### Attach Attributes to a Group

```php
$schema->attachAttributesToGroup($group, attributeIds: [4, 7, 12]);
```

Attributes not in the given IDs are unaffected. The package does not enforce entity type — apply any additional constraints (e.g. `entity_type = product`) at the request/validation layer.

## Enum Values

### Create / Update / Delete

```php
$enum = $schema->createEnum($attribute, [
    'code'         => 'red',
    'translations' => [
        ['locale_id' => 1, 'label' => 'Red'],
        ['locale_id' => 2, 'label' => 'Красный'],
    ],
]);

$schema->updateEnum($enum, ['code' => 'crimson']);

$schema->deleteEnum($enum); // dispatches AttributeEnumDeleted
```

## Querying

All query methods accept an optional `callable` modifier. Without a modifier they return a `Collection`. Pass a modifier to apply scopes, sorting, filtering, or pagination — whatever the caller needs:

```php
// Collection (default)
$attributes = $schema->getAttributes();

// Paginated with custom scopes
$paginated = $schema->getAttributes(
    fn ($q) => $q->where('entity_type', 'product')->paginate(15)
);

// Enums for a specific attribute
$enums = $schema->getEnums($attribute, fn ($q) => $q->orderBy('sort')->get());

// Types and groups
$types  = $schema->getTypes();
$groups = $schema->getGroups(fn ($q) => $q->paginate(15));
```

## Find by ID

```php
$attribute = $schema->getAttribute(42);
$group     = $schema->getGroup(3);
$enum      = $schema->getEnum(18);
$type      = $schema->getType(1);
```

Each method calls `findOrFail()` and throws `ModelNotFoundException` if the record does not exist.

## Search

Full-text search via [Laravel Scout](https://laravel.com/docs/scout). Throws `SearchNotAvailableException` if the configured attribute model does not use Scout:

```php
use Jurager\Eav\Exceptions\SearchNotAvailableException;

try {
    $results = $schema->searchAttributes('color', fn ($b) => $b->paginate(15));
} catch (SearchNotAvailableException $e) {
    // Scout not installed or attribute model not Searchable
}
```

The modifier receives a Scout `Builder`. Apply any Scout constraints before pagination.

## Events

Every mutation dispatches an event from `Jurager\Eav\Events\`. See [Events](events.md) for the full list.
