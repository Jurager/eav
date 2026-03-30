---
title: Translations
weight: 85
---

# Translations

`TranslationManager` handles locale CRUD and persists translated labels for any model that exposes a `translations()` MorphToMany relation.

```php
use Jurager\Eav\Managers\TranslationManager;

$manager = app(TranslationManager::class);
```

> [!NOTE]
> When using `AttributeSchemaManager`, translations are handled automatically — pass a `translations` key in the data array and the manager calls `TranslationManager::save()` for you. Use `TranslationManager` directly only when persisting translations on non-EAV models or managing locales.

## Managing Locales

```php
// Retrieve
$locales = $manager->getLocales();                              // Collection
$locales = $manager->getLocales(fn ($q) => $q->paginate(15)); // Paginator
$locale  = $manager->getLocale(1);

// Create / Update / Delete
$locale = $manager->createLocale(['code' => 'de', 'name' => 'German']);
$locale = $manager->updateLocale($locale, ['name' => 'Deutsch']);
$manager->deleteLocale($locale);
```

`createLocale`, `updateLocale`, and `deleteLocale` automatically flush the `LocaleRegistry` cache so that subsequent lookups reflect the change.

## Saving Translations

`save()` works with any Eloquent model that has a `translations()` MorphToMany relation pointing to the `entity_translations` table:

```php
$manager->save($model, [
    ['locale_id' => 1, 'label' => 'Color'],
    ['locale_id' => 2, 'label' => 'Цвет'],
]);
```

Entries without a `label` value are discarded. The relation is synced — locales not present in the array are removed.

### Optional Params

Additional display fields are packed into the `params` JSON column. Any combination of `hint`, `placeholder`, and `short_name` may be included:

```php
$manager->save($attribute, [
    [
        'locale_id'   => 1,
        'label'       => 'Color',
        'hint'        => 'Choose the primary color',
        'placeholder' => 'e.g. red',
    ],
]);
```

## Translating Non-EAV Models

Any application model can use the same translation table. Add the relation:

```php
use Jurager\Eav\Support\EavModels;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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

Then use `TranslationManager::save()` as with any EAV model:

```php
app(TranslationManager::class)->save($region, [
    ['locale_id' => 1, 'label' => 'Europe'],
    ['locale_id' => 2, 'label' => 'Европа'],
]);
```
