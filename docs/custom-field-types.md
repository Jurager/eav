---
title: Custom Field Types
weight: 70
---

# Custom Field Types

Domain-specific field types (e.g. `measurement`) are not included in the package. Implement and register them in your application.

## Implement a Field

Extend `Jurager\Eav\Fields\Field` and implement the three abstract methods:

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
        // convert to base unit and return float
        return (float) $value['value'];
    }
}
```

### Overriding cardinality / localization validation

If your field has a non-standard payload shape (e.g. measurement accepts `{value, unit_id}` instead of a plain scalar), override `validatePayload()` instead of `validate()`:

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

`validate()` receives a single value. `validatePayload()` handles the outer cardinality and localization envelope before delegating to `validate()`.

## Available Storage Column Constants

```php
Field::STORAGE_TEXT      // value_text
Field::STORAGE_INTEGER   // value_integer
Field::STORAGE_FLOAT     // value_float
Field::STORAGE_BOOLEAN   // value_boolean
Field::STORAGE_DATE      // value_date
Field::STORAGE_DATETIME  // value_datetime
```

## Register via Config

Add the type in `config/eav.php`:

```php
'types' => [
    // built-in types …
    'measurement' => \App\Fields\MeasurementField::class,
],
```

## Register at Runtime

Register in `AppServiceProvider::boot()` or any service provider:

```php
use Jurager\Eav\AttributeFieldRegistry;

public function boot(): void
{
    $this->app->make(AttributeFieldRegistry::class)
        ->register('measurement', \App\Fields\MeasurementField::class);
}
```

> [!NOTE]
> The type code must match the `code` value in the `attribute_types` table for the corresponding attribute type record.
