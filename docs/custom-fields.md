---
title: Creating custom fields
weight: 41
---

You can extend a built-in field to change how values are handled. For example, this text field stores uppercase strings:

```php
namespace App\Fields;

use Jurager\Eav\Fields\Text;

class UppercaseText extends Text
{
    protected function normalize(mixed $value): ?string
    {
        return $value === null ? null : strtoupper($value);
    }
}
```

## Registering the field

Add the class to `config/eav.php`:

```php
'types' => [
    'uppercase' => \App\Fields\UppercaseText::class,
],
```

Create a matching type record, then use its code when defining an attribute:

```php
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Models\AttributeType;

AttributeType::firstOrCreate(['code' => 'uppercase']);

Schema::attribute('sku', 'product')->type('uppercase')->create();
```

You can also register a field in your service provider's `boot()` method:

```php
use App\Fields\UppercaseText;
use Jurager\Eav\Fields\FieldFactory;

app(FieldFactory::class)->register('uppercase', UppercaseText::class);
```

## Defining a new value type

To start from `Jurager\Eav\Fields\Field`, implement these methods:

| Method | Purpose |
|---|---|
| `column()` | Return an `AttributeStorage` case for the value |
| `validate()` | Check a value and use `addError()` to report failures |
| `normalize()` | Convert an accepted value to its storage format |

Override `resolve()` to customize the value returned when reading. For array or object payloads, override `validatePayload()` to handle their shape, or return `true` from `isComposite()` when one value is an object such as `{"value": 2, "unit_id": 5}`. A composite value of a single-value attribute is normalized and stored as one value; a multiple attribute takes a list of such objects. Override `ruleSubject()` to choose what attribute rules are checked against, and `baseRules()` to prepend rules such as `numeric`.

A field that cannot be translated can return `false` from its static `localizable()` method. Enable supported capabilities on the type record before using flags such as `multiple` or `searchable` on an attribute.
