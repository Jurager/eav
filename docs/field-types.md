---
title: Field Types
weight: 40
---

## Built-in Types

Every attribute has a field type that determines which typed column its values are stored in and how those values are validated. The package ships the following types out of the box:

| Code | Storage column | Notes |
|---|---|---|
| `text` | `value_text` | Short string (max 255 characters) |
| `textarea` | `value_text` | Long text |
| `number` | `value_float` | Integer or float |
| `boolean` | `value_boolean` | `true` / `false` |
| `date` | `value_date` | ISO date |
| `select` | `value_integer` | Stores the enum option ID from `attribute_enums` |
| `image` | `value_text` | File path or URL |
| `file` | `value_text` | File path or URL |
| `link` | `value_text` | Absolute HTTP/HTTPS URL |

## Attribute Flags

Flags are set per attribute definition and control how values are stored and validated:

| Flag | Behaviour |
|---|---|
| `localizable` | Value is stored per locale |
| `multiple` | Allows storing multiple values for the same attribute |
| `mandatory` | Value is required on fill |
| `unique` | Value must be unique across all entity instances |
| `filterable` | Value is included in the Scout index and registered as a Meilisearch `filterableAttribute`; also available for Eloquent query scopes |
| `searchable` | Value is included in the Scout search index and available for full-text search |

Each field type declares which flags it supports. Flags that the type doesn't support are silently forced to `false` — for example, a `boolean` attribute is never `localizable` no matter what you ask for at the API layer.

## Validation Rules

Beyond the type-level constraints, you may attach extra validation rules to an attribute by storing them as a JSON array in the `validations` column:

```json
[
  { "type": "min_length", "value": 3 },
  { "type": "max_length", "value": 100 },
  { "type": "regex",      "value": "/^[a-z]+$/i" }
]
```

These map to Laravel's standard validation rules at runtime:

| Rule | Laravel equivalent |
|---|---|
| `min_length` | `min:N` |
| `max_length` | `max:N` |
| `min` | `min:N` |
| `max` | `max:N` |
| `regex` | `regex:pattern` |
| `email` | `email` |
| `url` | `url` |
| `date_format` | `date_format:format` |
| `after` | `after:date` |
| `before` | `before:date` |

## Working With Select Fields

When a field has type `select`, you may cast it to `SelectField` to access the resolved enum model and its localized labels:

```php
use Jurager\Eav\Fields\SelectField;

/** @var SelectField $field */
$field = $product->eav()->field('color');

$field->enum();             // ?AttributeEnum — single-select resolved model
$field->enums();            // array<AttributeEnum> — multi-select resolved models
$field->label();            // string|array|null — translated label(s) for current locale
$field->label(localeId: 2); // label for a specific locale
```

## Working With File and Image Fields

`FileField` and `ImageField` expose `HasFileStorage` helpers for URL resolution and existence checks on Laravel's storage disks:

```php
$field = $product->eav()->field('photo');

$field->url();                            // string|array|null — public URL(s) on the 'public' disk
$field->url(disk: 's3');                  // URL(s) on a named disk
$field->url(disk: 'public', localeId: 2); // localized file URL
$field->firstUrl();                       // ?string — first URL from a multiple-file field
$field->exists();                         // bool — check file existence in storage
```

## Defining Custom Field Types

You may define your own field type by extending the `Field` base class and implementing three abstract methods. The example below stores a measurement with a unit reference:

```php
use Jurager\Eav\Fields\Field;

class MeasurementField extends Field
{
    public function column(): string
    {
        return Field::STORAGE_FLOAT;
    }

    protected function validate(mixed $value): bool
    {
        if (! is_array($value) || ! isset($value['value'], $value['measurement_unit_id'])) {
            return $this->addError('Measurement value must contain value and measurement_unit_id.');
        }

        if (! is_numeric($value['value'])) {
            return $this->addError('Measurement value must be numeric.');
        }

        return true;
    }

    protected function normalize(mixed $value): float
    {
        return (float) $value['value'];
    }
}
```

The `column` method returns one of the storage constants exposed by the base class:

```php
Field::STORAGE_TEXT      // value_text
Field::STORAGE_INTEGER   // value_integer
Field::STORAGE_FLOAT     // value_float
Field::STORAGE_BOOLEAN   // value_boolean
Field::STORAGE_DATE      // value_date
Field::STORAGE_DATETIME  // value_datetime
```

### Handling Non-Standard Payload Shapes

If your field accepts a complex payload — for example, `{value, unit_id}` rather than a plain scalar — you should override `validatePayload()` to handle cardinality and localization before delegating to `validate()`:

```php
protected function validatePayload(mixed $values): bool
{
    if ($this->isMultiple()) {
        if (! is_array($values)) {
            return $this->addError('Expected an array of measurement objects.');
        }

        return array_all($values, fn ($v) => $this->validate($v));
    }

    return $this->validate($values);
}
```

### Registering Custom Types

To make a custom field type available, you should register it in either configuration or a service provider. The type code must match the `code` value of the corresponding record in the `attribute_types` table.

You may register the type through the configuration file:

```php
// config/eav.php
'field_types' => [
    'measurement' => \App\Fields\MeasurementField::class,
],
```

Or at runtime through the `FieldTypeRegistry`:

```php
use Jurager\Eav\Registry\FieldTypeRegistry;

public function boot(): void
{
    $this->app->make(FieldTypeRegistry::class)
        ->register('measurement', \App\Fields\MeasurementField::class);
}
```
