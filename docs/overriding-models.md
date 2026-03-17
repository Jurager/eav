---
title: Overriding Models
weight: 110
---

# Overriding Models

Every model reference inside the package is resolved through `EavModels` using the `eav.models` config map. Swapping any model is a two-step process.

## Step 1 — Create Your Subclass

```php
// app/Models/Attribute.php

use Jurager\Eav\EavModels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attribute extends \Jurager\Eav\Models\Attribute
{
    // Add domain-specific relations
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    // Extend eager loading
    public function scopeWithRelations(Builder $query): Builder
    {
        return parent::scopeWithRelations($query)
            ->with(['measurement.translations', 'measurement.units']);
    }
}
```

## Step 2 — Update the Config

```php
// config/eav.php

'models' => [
    'attribute'          => \App\Models\Attribute::class,
    'attribute_type'     => \App\Models\AttributeType::class,
    'attribute_group'    => \App\Models\AttributeGroup::class,
    'attribute_enum'     => \App\Models\AttributeEnum::class,
    'entity_attribute'   => \App\Models\EntityAttribute::class,
    'entity_translation' => \App\Models\EntityTranslation::class,
    'locale'             => \App\Models\Locale::class,
],
```

## Available Model Keys

| Key | Default class | Override reason |
|---|---|---|
| `attribute` | `Jurager\Eav\Models\Attribute` | Add relations, Scout, custom scopes |
| `attribute_type` | `Jurager\Eav\Models\AttributeType` | Add filters, sortable, extra columns |
| `attribute_group` | `Jurager\Eav\Models\AttributeGroup` | Add filters, sortable |
| `attribute_enum` | `Jurager\Eav\Models\AttributeEnum` | Add filters, sortable |
| `entity_attribute` | `Jurager\Eav\Models\EntityAttribute` | Add factory, custom casts |
| `entity_translation` | `Jurager\Eav\Models\EntityTranslation` | Add custom fields |
| `locale` | `Jurager\Eav\Models\Locale` | Add extra columns or relations |

> [!NOTE]
> Relations and observers defined in the package base models (e.g. cascade deletes in `booted()`) remain active in your subclasses. Only override what you need to extend, not replace.
