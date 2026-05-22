---
title: Localization
weight: 70
---

## Locale Registry

`LocaleRegistry` resolves locale IDs and codes using a single cached query per request. You may use it anywhere you need to translate between the two:

```php
use Jurager\Eav\Registry\LocaleRegistry;

$registry = app(LocaleRegistry::class);

$registry->defaultLocaleId();    // ID for the app.locale config value
$registry->localeId('en');       // locale ID by code
$registry->localeCode(1);        // locale code by ID
$registry->resolve('ru');        // ID by code, or default if not found
$registry->validLocaleIds();     // all valid locale IDs
$registry->isValidLocaleId(2);   // check if a locale ID exists
$registry->forget();             // clear cache (useful in tests)
```

## Per-Locale Attribute Values

When an attribute has `localizable: true`, values are stored per locale. You may write multiple translations in a single call and read them back by locale ID:

```php
// Write
$product->eav()->set('name', [
    ['locale_id' => 1, 'values' => 'T-Shirt'],
    ['locale_id' => 2, 'values' => 'Футболка'],
])->save('name');

// Read
$product->eav()->value('name', localeId: 2); // 'Футболка'
```

When no locale is specified, the default locale from `LocaleRegistry::defaultLocaleId()` is used.

## Managing Locales

`TranslationManager` handles locale CRUD. Each write method flushes the `LocaleRegistry` cache automatically, so subsequent lookups always see the latest data:

```php
use Jurager\Eav\Managers\TranslationManager;

$manager = app(TranslationManager::class);

$locales = $manager->getLocales();                              // Collection
$locales = $manager->getLocales(fn ($q) => $q->paginate(15));   // Paginator
$locale  = $manager->getLocale(1);

$locale = $manager->createLocale(['code' => 'de', 'name' => 'German']);
$locale = $manager->updateLocale($locale, ['name' => 'Deutsch']);
$manager->deleteLocale($locale);
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

When you use `SchemaManager` to create or update attributes, translations are handled automatically — pass the `translations` array in the data payload. You should reach for `TranslationManager::save()` directly only for non-EAV models or standalone locale management.

## Translating Non-EAV Models

Any application model may use the same `entity_translations` table. Add the relation using the `EavModels` helper so your model picks up the configured class overrides:

```php
use Jurager\Eav\Support\EavModels;

class Region extends Model
{
    public function translations(): MorphToMany
    {
        return $this->morphToMany(
                EavModels::class('locale'),
                'entity',
                'entity_translations',
            )
            ->using(EavModels::class('entity_translation'))
            ->withPivot(['id', 'label', 'params', 'updated_at']);
    }
}
```

Once the relation is in place, you may call `save()` exactly as you would for an EAV model:

```php
app(TranslationManager::class)->save($region, [
    ['locale_id' => 1, 'label' => 'Europe'],
    ['locale_id' => 2, 'label' => 'Европа'],
]);
```
