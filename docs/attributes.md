---
title: Reading and writing attributes
weight: 30
---

You can read and write values through the model's `eav()` method.

## Reading values

To retrieve the value of an attribute, use the `value` method:

```php
$product = Product::find(1);

$value = $product->eav()->value('color');
```

For localizable attributes, you may request a specific locale:

```php
$value = $product->eav()->value('description', localeId: 2);
```

When no locale is specified, the current locale is used: the first active one, otherwise the application's (`App::setLocale()`) — see [Localization](localization.md).

## Writing values

Attribute values behave like the entity's own columns: set them, then save the entity.

```php
$product->color = 'red';
$product->size = 'L';
$product->save();
```

You may also use the manager, including when an attribute code matches a column or relation:

```php
$product->eav()->set('color', 'red')->set('size', 'L')->save(); // Save all pending values.
$product->eav()->set('color', 'red')->save('color');            // Save color only.
```

Set a value to `null` to remove it on save. Use `isDirty()` and `getDirty()` to inspect unsaved changes. Unknown codes return `null` on read and throw `UnknownAttributeException` on write.

Omitting the locale writes to the current locale.

For localizable attributes, pass an array of locale translations:

```php
$product->eav()->set('description', [
    ['locale_id' => 1, 'values' => 'English description'],
    ['locale_id' => 2, 'values' => 'Russian description'],
])->save('description');
```

For multi-value attributes (`multiple: true`), pass an array of values:

```php
$product->eav()->set('tags', ['sale', 'new', 'featured'])->save('tags');
```

`set()` replaces the list. Use `add()` to append values:

```php
$product->eav()->add('tags', 'featured')->save('tags');
$product->eav()->add('tags', ['sale', 'new'])->save('tags');
```

For single-value attributes, `add()` behaves like `set()`.

## Persisting a full set

To replace every stored value on the entity with a new set, use `replace`:

```php
$product->eav()->replace($fields); // Remove values omitted from $fields.
```

To add or update values without removing the rest, use `attach`:

```php
$product->eav()->attach($fields);
```

Both methods accept `array<string, Field>` — the shape returned by `validate()` below.

## Validation in controllers

Validate the request structure with a FormRequest, then call `$model->validate()` for attribute rules. It checks required attributes against submitted and stored values, returning only the submitted fields. Persist them with `attach()`:

```php
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;

class AttachProductAttributeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'attributes' => ['required', 'array', 'min:1'],
        ];
    }
}

class ProductController extends Controller
{
    public function attachAttributes(AttachProductAttributeRequest $request, Product $product): Response
    {
        $fields = $product->validate($request->validated()['attributes']);

        $product->eav()->attach($fields);

        return response('', 204);
    }
}
```

Validation failures throw `ValidationException` and return a `422` response.

## Batch import

For multiple entities, see [importing attribute values](importing.md).

## Detaching values

To remove stored values for specific attribute IDs, use `detach`:

```php
$product->eav()->detach([12, 34]);
```
