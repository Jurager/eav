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

`TranslationManager` handles locale CRUD. Each write method flushes the `LocaleRegistry` cache automatically:

```php
use Jurager\Eav\Managers\TranslationManager;

$manager = app(TranslationManager::class);

$locales = $manager->locales();                              // Collection
$locales = $manager->locales(fn ($q) => $q->paginate(15));  // Paginator
$locale  = $manager->locale(1);                             // throws ModelNotFoundException if missing

$locale = $manager->create(['code' => 'de', 'name' => 'German']);
$locale = $manager->update($locale, ['name' => 'Deutsch']);
$manager->delete($locale);
```

## Saving Translations

The `save` method syncs translated labels for any model with a `translations()` MorphToMany relation:

```php
$manager->save($attribute, [
    ['locale_id' => 1, 'label' => 'Color'],
    ['locale_id' => 2, 'label' => 'Цвет'],
]);
```

Locales not present in the array are removed; entries without a `label` are discarded.

You may also store optional display fields in the `params` JSON column:

```php
$manager->save($attribute, [
    [
        'locale_id'   => 1,
        'label'       => 'Color',
        'hint'        => 'Choose the primary color',
        'placeholder' => 'e.g. red',
        'short_name'  => 'Clr',
    ],
]);
```

To update only specific locales without removing existing translations for others, pass `partial: true`:

```php
$manager->save($attribute, [
    ['locale_id' => 3, 'label' => 'Farbe'],
], partial: true);
```

When you use `SchemaManager` to create or update attributes, translations are handled automatically — pass the `translations` array in the data payload. Reach for `TranslationManager::save()` directly only for non-EAV models or standalone locale management.

## Batch Saving Translations

To sync translations for many models in a single upsert, use `batch()`. This is significantly faster than calling `save()` in a loop during imports:

```php
app(TranslationManager::class)->batch([
    [$attribute1, [['locale_id' => 1, 'label' => 'Color'], ['locale_id' => 2, 'label' => 'Цвет']]],
    [$attribute2, [['locale_id' => 1, 'label' => 'Size'],  ['locale_id' => 2, 'label' => 'Размер']]],
]);
```

Each element is a two-item tuple of `[Model, translations]`. The second element uses the same format as `save()`. Entries without a `label` are discarded.

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

The `active()` scope restricts the relation to the locales set for the current request. Once the relation is in place, call `save()` exactly as you would for an EAV model:

```php
app(TranslationManager::class)->save($region, [
    ['locale_id' => 1, 'label' => 'Europe'],
    ['locale_id' => 2, 'label' => 'Европа'],
]);
```
