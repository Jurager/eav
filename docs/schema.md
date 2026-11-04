---
title: Managing the attribute schema
weight: 50
---

Use the `Schema` facade to manage attributes, groups, and enum options.

```php
use Jurager\Eav\Facades\Schema;
```

For reading and writing attribute *values* on entity instances, use the `eav()` accessor described in [Reading & Writing Attributes](attributes.md).

## Creating attributes, groups, and enum options

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

`type()`, `group()`, and `label()` resolve a code to an ID and throw `FluentBuilderException` when it doesn't exist. `type()` is required on an attribute builder before `create()` — attributes have no default type.

You can set flags and validation rules using fluent methods:

```php
Schema::attribute('weight', 'product')
    ->type('number')
    ->validations([['type' => 'min', 'value' => 0]])
    ->create();
```

Use `fill()` to apply validated data:

```php
Schema::attribute($data['code'], $data['entity_type'])->fill($data)->create();
```

New groups and attributes are appended to their sort order unless `sort` is set.

Schema queries use display order. Add `ordered()` when querying models directly.

## Editing attributes, groups, and enum options

Pass an existing model instead of a code, and the builder switches from creating to editing. The same setters apply — call `update()` instead of `create()`:

```php
Schema::group($group)->label('Measurements', 'en')->update();

Schema::attribute($color)
    ->required(false)
    ->label('Primary Color', 'en')
    ->update();

Schema::enum($red)->sort(0)->update();
```

Omit `type()` to keep the existing type. Use `create()` with a code and `update()` with an existing model.

## Deleting

```php
Schema::attribute($attribute)->delete();
Schema::group($group)->delete();
Schema::enum($enum)->delete();
```

Deleting a group or option keeps the attributes and stored values that reference it. Reassign or remove those references if needed.

Deleting an attribute keeps its values for `$attribute->restore()`. Force deletion removes the attribute and its values permanently.

## Deleting attributes permanently

Deleting an attribute keeps its values for `$attribute->restore()`. Force deletion removes the attribute and its values permanently.

To purge a soft-deleted attribute after a restore window, dispatch `PruneAttribute`:

```php
use Jurager\Eav\Jobs\PruneAttribute;

PruneAttribute::dispatch($attribute->id)->delay(now()->addDays(30));
```

## Repositioning attributes and groups

`moveTo()` moves an attribute or group to a zero-based position and renumbers its siblings automatically. Enum options don't support repositioning.

```php
Schema::attribute($attribute)->moveTo(0); // move to the top of its group
Schema::group($group)->moveTo(2);
```

An attribute moves within its group, or among all ungrouped attributes.

## Assigning attributes to a group

`attach()` assigns existing attributes to a group by ID, without touching any other row:

```php
Schema::group($group)->attach([4, 7, 12]);
```

## Finding or creating an attribute

`firstOrCreate()` finds an attribute by code. For an existing attribute, it adds entity types and labels while keeping other settings:

```php
Schema::attribute('color', 'product')
    ->type('select')
    ->label('Color', 'en')
    ->firstOrCreate();

Schema::attribute('color', 'service')->type('select')->firstOrCreate(); // now applies to both
```

## Finding records

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
    ->forEntityType('product')
    ->paginate(15);

$enums  = Schema::enums($attribute)->orderBy('sort')->get();
$types  = Schema::types()->get();
$groups = Schema::groups()->paginate(15);
```

## Batch creating attributes

Pass unsaved builders to `Schema::batch()` to create attributes in bulk:

```php
$created = Schema::batch([
    Schema::attribute('color', 'product')->type('text')->label('Color', 'en'),
    Schema::attribute('weight', 'product')->type('number')->label('Weight', 'en'),
], fireEvents: false);

$created->get('color'); // Attribute, keyed by code
```

Attribute codes are globally unique. A duplicate code rolls back the batch. Use `firstOrCreate()` for repeatable imports.

Pass `fireEvents: false` to suppress `AttributeCreated` events during large imports.

## Full-Text search over attributes

When [Laravel Scout](https://laravel.com/docs/scout) is configured on the `Attribute` model, `search()` looks up attributes by code and translated labels:

```php
use Jurager\Eav\Exceptions\SearchNotAvailableException;

try {
    $results = Schema::search('color')->paginate(15);
} catch (SearchNotAvailableException $e) {
    // Scout is not configured on the attribute model
}
```
