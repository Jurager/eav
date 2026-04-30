---
title: Localization
weight: 70
---

# Localization

## Locale Registry

`LocaleRegistry` resolves locale IDs and codes with a single cached query per request:

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

## Per-locale Attribute Values

When an attribute has `localizable: true`, values are stored per locale:

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

`TranslationManager` handles locale CRUD. Each write method flushes the `LocaleRegistry` cache automatically:

```php
use Jurager\Eav\Managers\TranslationManager;

$manager = app(TranslationManager::class);

$locales = $manager->getLocales();                               // Collection
$locales = $manager->getLocales(fn ($q) => $q->paginate(15));  // Paginator
$locale  = $manager->getLocale(1);

$locale = $manager->createLocale(['code' => 'de', 'name' => 'German']);
$locale = $manager->updateLocale($locale, ['name' => 'Deutsch']);
$manager->deleteLocale($locale);
```

## Saving Translations

`save()` syncs translated labels for any model with a `translations()` MorphToMany relation:

```php
$manager->save($attribute, [
    ['locale_id' => 1, 'label' => 'Color'],
    ['locale_id' => 2, 'label' => 'Цвет'],
]);
```

Locales not present in the array are removed. Entries without a `label` are discarded.

Optional display fields are stored in the `params` JSON column:

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

> [!NOTE]
> When using `SchemaManager`, translations are handled automatically — pass `translations` in the data array. Use `TranslationManager::save()` directly only for non-EAV models or standalone locale management.

## Translating Non-EAV Models

Any application model can use the same `entity_translations` table. Add the relation:

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

Then call `save()` as with any EAV model:

```php
app(TranslationManager::class)->save($region, [
    ['locale_id' => 1, 'label' => 'Europe'],
    ['locale_id' => 2, 'label' => 'Европа'],
]);
```
