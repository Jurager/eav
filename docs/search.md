---
title: Indexing attributes with Scout
weight: 83
---

Install Scout to index attribute values:

```bash
composer require laravel/scout
```

For Meilisearch, also install `meilisearch/meilisearch-php` and configure the Scout driver.

To include EAV values in your search index, add the `HasSearchableAttributes` trait to your model. You must also use `Laravel\Scout\Searchable` and resolve the trait method conflict explicitly:

```php
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Concerns\HasSearchableAttributes;
use Jurager\Eav\Contracts\Attributable;
use Laravel\Scout\Searchable;

class Product extends Model implements Attributable
{
    use HasAttributes, HasSearchableAttributes, Searchable {
        HasSearchableAttributes::toSearchableArray insteadof Searchable;
        HasSearchableAttributes::shouldBeSearchable insteadof Searchable;
    }
}
```

The package updates search documents and index settings when attributes or values change.

## Building the search array

Attributes marked `searchable` or `filterable` are included in the search document.

To add model-specific fields alongside attribute data, override `toSearchableArray()`:

```php
public function toSearchableArray(): array
{
    $data = $this->eav()->indexData();

    return ['id' => (string) $this->getScoutKey(), 'code' => $this->code, ...$data];
}
```

## Automatic index sync

Attribute changes update affected documents and filter settings automatically. Value changes are indexed after commit, including inherited values on variants.

## Meilisearch: syncing filterableAttributes

With Meilisearch, filterable attribute paths are merged with the model's Scout settings and `InteractsWithIndex::indexFields()` permissions.

Attribute paths are preserved when running `scout:sync-index-settings`.

## Custom field types and filterableKeys

Override `filterableKeys()` to register additional paths for a custom field:

```php
public function filterableKeys(): array
{
    return [$this->code(), "{$this->code()}_label"];
}
```

Each key is prefixed with `attributes.` in Meilisearch.

## Running the queue worker

Run a queue worker for index updates:

```bash
php artisan queue:work
```
