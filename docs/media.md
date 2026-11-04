---
title: Using media fields
weight: 42
---

With `jurager/media`, register `MediaField` to store media IDs and return media models:

```php
// config/eav.php
'types' => [
    'image' => \Jurager\Eav\Media\MediaField::class,
    'file'  => \Jurager\Eav\Media\MediaField::class,
],
```

The model must implement `Attributable` and `InteractsWithMedia`. Attribute media collections are registered automatically. Define static collections as usual:

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('gallery');
}
```

Deleted media values are cleared automatically. To remove unreferenced media, add `Jurager\Eav\Media\OrphanedMediaCleaner` to `media.cleaners`.

Install `jurager/media` only if you use this integration.

## Finding media attributes

Use `FieldFactory::using()` to find attributes by field class across an entity type:

```php
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Media\MediaField;

$mediaAttributes = app(FieldFactory::class)->using(MediaField::class, 'product');

$mediaAttributes->pluck('code'); // attribute codes
$mediaAttributes->keys();        // attribute ids
```

The result includes subclasses and ignores the scope of individual entities.
