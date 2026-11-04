---
title: Eav
weight: 1
---

Eav adds typed attributes to your Eloquent models. You can define fields at runtime, translate their values, and share attributes between entity types.

```php
$product->eav()->set('color', 'blue')->save();

$product->eav()->value('color'); // 'blue'
```

## Getting started

The package requires PHP 8.4 and Laravel 13 or higher. Start with [installation and model setup](getting-started.md).

## Basic usage

- [Reading and writing attributes](attributes.md)
- [Creating attributes, groups, and options](schema.md)
- [Choosing field types](field-types.md)
- [Querying by attribute values](querying.md)
- [Translating values and labels](localization.md)
- [Importing values](importing.md)

## Customizing the package

- [Creating custom fields](custom-fields.md)
- [Using media fields](media.md)
- [Scoping and inheriting attributes](inheritance.md)
- [Working with variants](variants.md)
- [Indexing with Scout](search.md)
- [Listening for events](events.md)
- [Cache and JSON:API integration](advanced.md)

See the [changelog](../CHANGELOG.md) for upgrade notes.
