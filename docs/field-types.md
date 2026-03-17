---
title: Field Types
weight: 60
---

# Field Types

## Built-in Types

| Code | Storage column | Description |
|---|---|---|
| `text` | `value_text` | Short string (max 255 chars) |
| `textarea` | `value_text` | Long text |
| `number` | `value_float` | Numeric (int or float) |
| `boolean` | `value_boolean` | `true` / `false` |
| `date` | `value_date` | ISO date |
| `select` | `value_integer` | Enum option ID from `attribute_enums` |
| `image` | `value_text` | File path or URL |
| `file` | `value_text` | File path or URL |
| `link` | `value_text` | Absolute HTTP/HTTPS URL |

## Attribute Flags

Each attribute definition carries flags that control its behaviour:

| Flag | Description |
|---|---|
| `localizable` | Value is stored per locale via `entity_translations` |
| `multiple` | Allows multiple values for the same attribute |
| `mandatory` | Value is required on fill |
| `unique` | Value must be unique across all entity instances |
| `filterable` | Attribute is available for filtering queries |
| `searchable` | Attribute value is included in the search index |

## Configurable Validation Rules

Extra validation rules can be stored on an attribute as a JSON array in the `validations` column:

```json
[
  { "type": "min_length", "value": 3 },
  { "type": "max_length", "value": 100 },
  { "type": "regex",      "value": "/^[a-z]+$/i" }
]
```

Supported rule types:

| Rule type | Laravel equivalent |
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
