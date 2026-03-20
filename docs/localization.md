---
title: Localization
weight: 80
---

# Localization

The package stores localized values via a polymorphic `entity_translations` table. Any model can be translated by adding a `translations()` relation.

## Locale Registry

The `LocaleRegistry` singleton resolves locale IDs and codes with a single cached query per request:

```php
use Jurager\Eav\Registry\LocaleRegistry;

$registry = app(LocaleRegistry::class);

$registry->defaultLocaleId();        // ID for app.locale config value
$registry->localeId('en');           // locale ID by code
$registry->localeCode(1);            // locale code by ID
$registry->validLocaleIds();         // all valid locale IDs
$registry->isValidLocaleId(2);       // check if locale ID exists
$registry->resolveLocaleId('ru');    // ID by code, or default if not found
$registry->reset();                  // clear cache (e.g. in tests)
```

## Translating Custom Models

Add a `translations()` relation to any model using the `entity_translations` table:

```php
use Jurager\Eav\EavModels;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

public function translations(): MorphToMany
{
    return $this->morphToMany(EavModels::class('locale'), 'entity', 'entity_translations')
        ->using(EavModels::class('entity_translation'))
        ->withPivot(['id', 'label', 'params', 'updated_at']);
}
```

## Localizable Attribute Values

When an attribute has `localizable: true`, values are stored per locale. Pass an array of locale translations when writing:

```php
$product->attributes()->set('name', [
    ['locale_id' => 1, 'values' => 'T-Shirt'],
    ['locale_id' => 2, 'values' => 'Футболка'],
]);
```

Read back for a specific locale:

```php
$product->attributes()->value('name', localeId: 2); // 'Футболка'
```

When no locale is specified, the default locale from `LocaleRegistry::defaultLocaleId()` is used.
