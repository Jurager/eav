---
title: Localization
weight: 70
---

## Locale Registry

`LocaleRegistry` is a scoped singleton that caches locale data for the duration of a request. It is the single source of truth for resolving locale IDs and codes:

```php
use Jurager\Eav\Registry\LocaleRegistry;

$registry = app(LocaleRegistry::class);

$registry->all();           // Collection<id, code>
$registry->ids();           // array of all locale IDs
$registry->code(1);         // ?string — locale code by ID
$registry->find('en');      // ?int — locale ID by code
$registry->has(1);          // bool — whether a locale ID exists
$registry->resolve('ru');   // int — ID by code, or default() if not found
$registry->current();       // int — first active locale that exists, or default()
$registry->default();       // int — ID for app.locale config value
$registry->forget();        // clear cache (useful in tests)
```

The registry is `scoped`, so in Octane environments the cache is reset between requests automatically. Active locales for a request are set via `set()`, typically from an `Accept-Language` middleware:

```php
$registry->set(['ru', 'en']);  // mark request-active locales
$registry->get();              // ?array — the active locale codes, or null
```

## Per-Locale Attribute Values

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

When no locale is specified, the default locale from `LocaleRegistry::default()` is used.

## Managing Locales

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

## Setting Labels for a Model

A translation doesn't exist on its own — it always belongs to a model. `Translator::for()` starts a builder scoped to that model; nothing is persisted until you call `save()`:

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

To fill a builder from an already-validated array — a controller's `$request->validated()['translations']`, for example — use `fill()`. Each entry may include the optional `hint`, `placeholder`, and `short_name` display fields alongside `label`:

```php
Translator::for($attribute)->fill([
    ['locale_id' => 1, 'label' => 'Color', 'hint' => 'Choose the primary color', 'placeholder' => 'e.g. red'],
])->save();
```

## Batch Setting Labels

To persist labels for many models in a single upsert, collect builders without saving them and pass them to `Translator::batch()`. This is significantly faster than calling `save()` in a loop during imports:

```php
Translator::batch([
    Translator::for($attribute1)->label('Color', 'en')->label('Цвет', 'ru'),
    Translator::for($attribute2)->label('Size', 'en')->label('Размер', 'ru'),
]);
```

## Translating Non-EAV Models

Any application model may use the same `entity_translations` table. Build the relation off `Eav::$localeModel` / `Eav::$entityTranslationModel` so your model picks up any [model overrides](installation.md#overriding-models) configured in `eav.models`:

```php
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

The `active()` scope restricts the relation to the locales set for the current request. Once the relation is in place, `Translator::for()` works exactly as it does for an EAV model:

```php
Translator::for($region)
    ->label('Europe', 'en')
    ->label('Европа', 'ru')
    ->save();
```
