---
title: Translating values and labels
weight: 70
---

## Managing locales

The `Translator` facade creates, edits, and deletes locales. Pass a code to create a new one, or an existing `Locale` to edit it:

```php
use Jurager\Eav\Facades\Translator;

$locale = Translator::locale('de')->name('German')->create();

Translator::locale($locale)->name('Deutsch')->update();
Translator::locale($locale)->delete();
```

```php
Translator::findLocale(1);  // throws ModelNotFoundException if missing
Translator::locales();      // Collection
Translator::locales(fn ($q) => $q->paginate(15)); // Paginator
```

## Per-Locale attribute values

When an attribute has `localizable: true`, values are stored per locale. Write multiple translations in a single call and read them back by locale ID:

```php
// Write
$product->eav()->set('name', [
    ['locale_id' => 1, 'values' => 'T-Shirt'],
    ['locale_id' => 2, 'values' => 'Футболка'],
])->save('name');

// Read
$product->eav()->value('name', localeId: 2); // 'Футболка'
```

When no locale is specified, the current locale is used, for reading and writing alike.

## Choosing the active locale

Use `LocaleRegistry` to resolve locale IDs and codes:

```php
use Jurager\Eav\Registry\LocaleRegistry;

$registry = app(LocaleRegistry::class);

$registry->find('en');    // Locale ID.
$registry->code(1);       // Locale code.
$registry->current();    // Current locale ID.
$registry->default();    // Application locale ID.
```

Active locales are reset between requests and jobs.

Values use the first active locale, falling back to the application locale. Set active locales to limit the translations returned for a request:

```php
$registry->set(['ru', 'en']);  // mark request-active locales
$registry->get();              // ?array — the active locale codes, or null
```

Add `SetLocaleContext` to read active locales from `Accept-Language` and set the application locale:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Jurager\Eav\Http\Middleware\SetLocaleContext;

->withMiddleware(fn (Middleware $middleware) => $middleware->api(prepend: [SetLocaleContext::class]))
```

## Setting labels for a model

Use `Translator::for()` to set labels on a model:

```php
Translator::for($attribute)
    ->label('Color', 'en')
    ->label('Цвет', 'ru')
    ->save();
```

`label()` resolves a locale code to an ID and throws `FluentBuilderException` when it doesn't exist. Saving replaces every translation on the model — locales not queued are removed. To update only the locales you queued and leave the rest alone, call `partial()`:

```php
Translator::for($attribute)->label('Farbe', 'de')->partial()->save();
```

When you create or edit attributes, groups, or enums via the [`Schema` facade](schema.md), call `->label()` on that builder instead — it saves translations the same way, in the same `create()`/`update()` call.

Use `fill()` for translation arrays. Entries may include `hint`, `placeholder`, and `short_name`:

```php
Translator::for($attribute)->fill([
    ['locale_id' => 1, 'label' => 'Color', 'hint' => 'Choose the primary color', 'placeholder' => 'e.g. red'],
])->save();
```

## Batch setting labels

Pass unsaved label builders to `Translator::batch()` for bulk updates:

```php
Translator::batch([
    Translator::for($attribute1)->label('Color', 'en')->label('Цвет', 'ru'),
    Translator::for($attribute2)->label('Size', 'en')->label('Размер', 'ru'),
]);
```

## Translating non-eav models

Add a translations relation to use labels on other models:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Eav;

class Region extends Model
{
    public function translations(): MorphToMany
    {
        return $this->morphToMany(Eav::$localeModel, 'entity', 'entity_translations')
            ->using(Eav::$entityTranslationModel)
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps()
            ->active();
    }
}
```

The `active()` scope limits labels to the request's locales. Use the same translator API:

```php
Translator::for($region)
    ->label('Europe', 'en')
    ->label('Европа', 'ru')
    ->save();
```
