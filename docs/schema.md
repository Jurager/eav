---
title: Managing Schema
weight: 50
---

## Introduction

The `Schema` facade creates, edits, deletes, and queries the schema — attribute groups, attributes, and enum options.

It's the single entry point for schema management; every operation below goes through it.

```php
use Jurager\Eav\Facades\Schema;
```

Schema manages what attributes exist and how they're configured.

For reading and writing attribute *values* on entity instances, use the `eav()` accessor described in [Reading & Writing Attributes](attributes.md).

## Creating Attributes, Groups, and Enum Options

`Schema::group()`, `Schema::attribute()`, and `Schema::enum()` return fluent builders. Nothing is persisted until you call `create()`:

```php
$group = Schema::group('dimensions')
    ->label('Dimensions', 'en')
    ->label('Размеры', 'ru')
    ->create();

$color = Schema::attribute('color', 'product')
    ->type('select')
    ->group('dimensions')
    ->required()
    ->searchable()
    ->label('Color', 'en')
    ->label('Цвет', 'ru')
    ->create();

Schema::enum($color, 'red')->label('Red', 'en')->label('Красный', 'ru')->create();
Schema::enum($color, 'blue')->sort(1)->create();
```

`type()`, `group()`, and `label()` resolve a code to an ID and throw `SchemaBuilderException` when it doesn't exist. `type()` is required on an attribute builder before `create()` — attributes have no default type.

Every other column is set dynamically, the same way Laravel's own `Schema::create()` column definitions work. Call any [attribute flag](field-types.md#attribute-flags), `sort`, `validations`, `meta`, or a column your own `Attribute` subclass adds, and it's queued for `create()`:

```php
Schema::attribute('weight', 'product')
    ->type('number')
    ->validations(['min' => 0])
    ->measurement_id($measurement->id)
    ->create();
```

To fill a builder from an already-validated array — a controller's `$request->validated()`, for example — use `fill()` instead of chaining individual setters:

```php
Schema::attribute($data['code'], $data['entity_type'])->fill($data)->create();
```

New groups are appended to the end of the sort order unless you set an explicit `sort`; new attributes are appended within their group (or within the entity type, if ungrouped).

## Editing Attributes, Groups, and Enum Options

Pass an existing model instead of a code, and the builder switches from creating to editing. The same setters apply — call `update()` instead of `create()`:

```php
Schema::group($group)->label('Measurements', 'en')->update();

Schema::attribute($color)
    ->required(false)
    ->label('Primary Color', 'en')
    ->update();

Schema::enum($red)->sort(0)->update();
```

`type()` is optional on an update — omit it and the attribute keeps its existing type; any other type-specific flag you didn't set is re-validated against that existing type. Calling `create()` on a builder constructed from an existing model, or `update()` on one constructed from a code, throws `SchemaBuilderException`.

## Deleting

```php
Schema::attribute($attribute)->delete();
Schema::group($group)->delete();
Schema::enum($enum)->delete();
```

Deleting a group or an enum option does not delete the attributes or values that reference it — detach them first if that matters for your data.

## Repositioning Attributes and Groups

`moveTo()` moves an attribute or group to a zero-based position and renumbers its siblings automatically. Enum options don't support repositioning.

```php
Schema::attribute($attribute)->moveTo(0); // move to the top of its group
Schema::group($group)->moveTo(2);
```

An attribute's siblings are the other attributes in the same group (or, if ungrouped, the other ungrouped attributes of the same entity type).

## Assigning Attributes to a Group

`attach()` assigns existing attributes to a group by ID, without touching any other row:

```php
Schema::group($group)->attach([4, 7, 12]);
```

## Finding or Creating an Attribute

`firstOrCreate()` looks up an attribute by the builder's entity type and code; if it already exists, only its translations are updated and every other queued field is ignored, so it's safe to call repeatedly without overwriting manual changes:

```php
Schema::attribute('color', 'product')
    ->type('select')
    ->label('Color', 'en')
    ->firstOrCreate();
```

## Finding Records

```php
Schema::findAttribute(42); // throws ModelNotFoundException if missing
Schema::findGroup(3);
Schema::findEnum(18);
Schema::findType(1);
```

## Querying the Schema

`attributes()`, `groups()`, `enums()`, and `types()` return an Eloquent `Builder` you may extend with any standard scopes, pagination, or ordering:

```php
$attributes = Schema::attributes()
    ->where('entity_type', 'product')
    ->paginate(15);

$enums  = Schema::enums($attribute)->orderBy('sort')->get();
$types  = Schema::types()->get();
$groups = Schema::groups()->paginate(15);
```

## Batch Creating Attributes

Calling `create()` one attribute at a time is fine for seeders, too slow for imports. To create many at once, collect builders without persisting them and pass them to `Schema::batch()`. It inserts every attribute in a single transaction and persists all translations in one additional batched upsert:

```php
$created = Schema::batch([
    Schema::attribute('color', 'product')->type('text')->label('Color', 'en'),
    Schema::attribute('weight', 'product')->type('number')->label('Weight', 'en'),
], fireEvents: false);

$created->get('product:color'); // Attribute, keyed by "{entity_type}:{code}"
```

`(entity_type, code)` is unique — `batch()` does not check for existing attributes before inserting, so passing a pair that already exists throws and rolls back the whole batch. When re-running an import, filter out attributes you've already created, or use `firstOrCreate()` per attribute when idempotency matters more than raw throughput.

Pass `fireEvents: false` to suppress `AttributeCreated` events during large imports.

## Full-Text Search Over Attributes

When [Laravel Scout](https://laravel.com/docs/scout) is configured on the `Attribute` model, `search()` looks up attributes by code and translated labels:

```php
use Jurager\Eav\Exceptions\SearchNotAvailableException;

try {
    $results = Schema::search('color')->paginate(15);
} catch (SearchNotAvailableException $e) {
    // Scout is not configured on the attribute model
}
```
