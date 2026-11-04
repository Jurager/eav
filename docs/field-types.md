---
title: Choosing field types
weight: 40
---

## Built-in types

The field type determines how a value is validated:

| Code | Value |
|---|---|
| `text` | String up to 255 characters |
| `textarea` | Long text |
| `number` | Integer or float |
| `boolean` | Boolean |
| `date` | Date or datetime, resolved as Carbon |
| `select` | Enum option ID |
| `image` | File path or URL |
| `file` | File path or URL |
| `link` | Absolute HTTP or HTTPS URL |

Text values support case-insensitive comparisons. Use `textarea` for long text and `link` for URLs.

## Attribute flags

Set these flags on an attribute definition:

| Flag                  | Behaviour                                                                                                                            |
|-----------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `localizable`         | Value is stored per locale                                                                                                           |
| `multiple`            | Allows storing multiple values for the same attribute                                                                                |
| `required`            | Value is required on fill                                                                                                            |
| `unique`              | Value must be unique across all entity instances                                                                                     |
| `filterable`          | Include the value in search filters |
| `searchable`          | Include the value in full-text search                                                       |
| `held_by`             | Who writes the value: `parent`, `variant`, or `both`                                            |
| `inherit_from_parent` | A variant with no value of its own reads the parent's                                                                                |

See [Variants](advanced.md#variants) for `held_by` and `inherit_from_parent`.

Unsupported flags are saved as `false`. For example, boolean values cannot be localized.

## Validation rules

Add Laravel validation rules through the `validations` array:

```json
[
  { "type": "min_length", "value": 3 },
  { "type": "max_length", "value": 100 },
  { "type": "regex",      "value": "/^[a-z]+$/i" }
]
```

These map to Laravel's standard validation rules at runtime:

| Rule          | Laravel equivalent   |
|---------------|----------------------|
| `min_length`  | `min:N`              |
| `max_length`  | `max:N`              |
| `min`         | `min:N`              |
| `max`         | `max:N`              |
| `regex`       | `regex:pattern`      |
| `email`       | `email`              |
| `url`         | `url`                |
| `date_format` | `date_format:format` |
| `after`       | `after:date`         |
| `before`      | `before:date`        |

## Working with Select fields

Select fields expose selected options and their translated labels:

```php
use Jurager\Eav\Fields\Select;

/** @var Select $field */
$field = $product->eav()->field('color');

$field->enum();             // ?AttributeEnum — single-select resolved model
$field->enums();            // array<AttributeEnum> — multi-select resolved models
$field->label();            // string|array|null — translated label(s) for current locale
$field->label(localeId: 2); // label for a specific locale
```

## Working with File and Image fields

`File` and `Image` return the stored path or URL. Extend `resolve()` to customize the result.

You can also [use media models](media.md) or [create your own field type](custom-fields.md).
