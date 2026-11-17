---
title: Searching entities
weight: 83
---

The package indexes attribute values with [Laravel Scout](https://laravel.com/docs/scout) and searches them through a search engine: full-text search with typo tolerance, per-locale search, filters, facets, sorting, and federated search across entity types.

[Meilisearch](https://www.meilisearch.com) is the built-in engine. Install Scout and the Meilisearch client, and set Scout's driver to `meilisearch`:

```bash
composer require laravel/scout meilisearch/meilisearch-php
```

Native federated search requires Meilisearch 1.10 or higher. You can also [add your own engine](#search-engines).

## Making a model searchable

Add the `HasSearchableAttributes` trait. It includes Scout's `Searchable` trait:

```php
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Concerns\HasSearchableAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes, HasSearchableAttributes;
}
```

Attributes are indexed according to their flags:

| Flag         | Effect                                                    |
|--------------|-----------------------------------------------------------|
| `searchable` | The value is matched by full-text search                  |
| `filterable` | The value can be filtered on and used as a facet          |
| `sortable`   | Results can be sorted by the value                        |

An attribute with none of these flags is not indexed. The attribute's type must allow a flag before an attribute can use it.

## Declaring model fields

Declare the model's own columns and computed values in `searchIndex()`. This one definition drives the document, the engine settings, and the fields a request may filter and sort by:

```php
use Jurager\Eav\Search\Index\Index;

public function searchIndex(Index $index): void
{
    $index->field('sku')->keyword()->searchable()->filterable();
    $index->field('price')->number()->filterable()->sortable();
    $index->field('created_at')->date()->sortable();

    $index->field('categories.id')
        ->number()
        ->using(fn (Product $product) => $product->categories->modelKeys())
        ->with('categories')
        ->filterable()
        ->alias('category');

    $index->distinct('parent_id');
}
```

A field reads the column named by its path unless you pass a closure to `using()`. Its type is taken from the model's casts, or set with `text()`, `keyword()`, `number()`, `date()`, or `boolean()`.

| Method       | Purpose                                                              |
|--------------|----------------------------------------------------------------------|
| `using()`    | Compute the value instead of reading a column                        |
| `with()`     | Relations to eager load before the value is read                     |
| `watch()`    | Columns whose change rebuilds the document, for computed fields      |
| `alias()`    | Other names the field answers to in filters, sorts, and facets       |
| `localized()`| The value is an array keyed by locale code                           |

`$index->distinct()` keeps one hit per value of a field, such as one variant per parent. `$index->settings([...])` sets Meilisearch settings for this index.

A save reindexes the entity only when a declared column changed. Attribute value changes are indexed after commit, including the variants that inherit them. Saving an entity together with its attribute values indexes it once.

## The search document

Attribute values are nested under `attributes`. A localizable value is keyed by locale code. A select is indexed by option code, and its labels are kept per locale under `labels`:

```json
{
    "id": 1,
    "sku": "RS-100",
    "attributes": {
        "price": 100,
        "title": {"en": "Red running shoes", "ru": "Красные кроссовки"},
        "color": "red",
        "released": 1767999600
    },
    "labels": {
        "color": {"en": "Red", "ru": "Красный"}
    }
}
```

Dates are indexed as Unix timestamps. Every locale is indexed, whichever one the request runs in.

## Searching

```php
use Jurager\Eav\Facades\Search;

$results = Search::for(Product::class)
    ->query('красн кросовки')
    ->where('brand', 'nike')
    ->whereBetween('price', [1000, 5000])
    ->filter($request->query('filter'))
    ->sort('-price,name')
    ->facets(['brand', 'color', 'price'])
    ->paginate(15);
```

`Search::for()` accepts a model, its class, or its morph alias. Refer to a field by its path, an alias, or the attribute code. The labels of a select are the field `labels.{code}`.

`paginate()` returns a `LengthAwarePaginator` of models in ranking order. The total is exact up to Meilisearch's `pagination.maxTotalHits`, which is 1000 by default. Raise it in the [index settings](#index-settings) to page deeper:

```php
$results->items();   // models
$results->total();
$results->facets();
$results->hits();    // documents, highlights, ranking scores
```

| Method          | Returns                                         |
|-----------------|-------------------------------------------------|
| `paginate()`    | A page of models, with hits and facets          |
| `get($limit)`   | A collection of models                          |
| `first()`       | The best match, or `null`                       |
| `keys($limit)`  | Keys of the matches, without loading models     |
| `count()`       | Number of matches                               |

A document whose entity no longer exists, for example after rows were deleted with a raw query, is removed from the index when a search finds it. Set `eav.search.prune` to `false` to turn this off.

Use `with()` to eager load relations on the models, or `loadUsing()` to change the query they are loaded with:

```php
Search::for(Product::class)
    ->query('shoes')
    ->with('media')
    ->loadUsing(fn ($query) => $query->where('published', true))
    ->paginate();
```

### Trashed entities

When Scout keeps soft-deleted models in the index (`scout.soft_delete`), they are left out of the results. Use `withTrashed()` to include them, or `onlyTrashed()` to search them alone.

### Locales

Text is matched against the searchable fields of the current locale. Meilisearch is told the language, so typo tolerance and word splitting follow it. Pass other locales to `locale()`:

```php
Search::for(Product::class)->query('кроссовки')->locale('ru')->paginate();

Search::for(Product::class)->query('shoes')->locale('en', 'ru')->paginate();
```

Filters, sorts, and facets on a localizable field use the first locale.

A locale's language is the first two letters of its code. Map other codes in `eav.search.engines.meilisearch.locales`. Searching in a locale that does not exist throws `InvalidSearchQueryException`.

Adding a locale syncs the index settings. Removing a locale or changing its code also rebuilds the documents that hold localized values, so no value stays indexed under a locale that is gone.

### Filters

`where()` accepts `=`, `!=`, `>`, `>=`, `<`, and `<=`. You can also use `whereNot()`, `whereIn()`, `whereNotIn()`, `whereBetween()`, `whereNotBetween()`, `whereNull()`, and `whereNotNull()`.

`filter()` accepts JSON:API filters as sent in a request, including `or` and `and` groups:

```php
Search::for(Product::class)->filter([
    'color' => ['red', 'blue'],
    'price' => ['gte' => 100, 'lte' => 500],
    'released' => ['gte' => '2026-01-01'],
    'or' => [['brand' => 'nike'], ['brand' => 'puma']],
]);
```

Values are cast to the field's type, so dates can be sent as date strings and booleans as `true` or `1`.

Filters are strict. A field that is not filterable, an unsupported operator, or a value that does not fit the field's type throws `InvalidSearchQueryException`, which renders as a 422. A condition is never dropped silently.

The `like` operator is rejected unless you set `eav.search.engines.meilisearch.contains` to `true`. It compiles to Meilisearch's `CONTAINS` operator, an experimental feature you must enable on the server.

### Sorting

`sort()` accepts a JSON:API sort string. Sorting by a field that is not sortable throws `InvalidSearchQueryException`:

```php
Search::for(Product::class)->sort('-price,name');

Search::for(Product::class)->orderBy('price', 'desc');
```

To lead the results with the documents matching a condition, use `partition()`:

```php
Search::for(Product::class)->partition(['in_stock' => true])->sort('price');
```

### Facets

Facets are keyed by the name you asked for. A numeric or date field gives a range; any other field gives its values with counts and labels:

```php
$results->facets();

[
    'color' => ['type' => 'terms', 'values' => [
        ['value' => 'red', 'label' => 'Red', 'count' => 12],
        ['value' => 'blue', 'label' => 'Blue', 'count' => 7],
    ]],
    'price' => ['type' => 'range', 'min' => 100, 'max' => 900],
]
```

A facet you filter on is counted without its own filter, so its other values stay selectable.

To search the values of a facet with many values, use `facetValues()`:

```php
Search::for(Product::class)->where('category', 5)->facetValues('brand', 'ni');
```

### Highlighting and ranking

```php
$results = Search::for(Product::class)
    ->query('leather')
    ->highlight('title')
    ->matching('all')
    ->threshold(0.3)
    ->paginate();

$hit = $results->hits()->first();

$hit->highlight('title'); // 'Red <em>leather</em> boots'
$hit->get('title');       // 'Red leather boots'
$hit->score;              // 0.87
$hit->model;
```

| Method        | Purpose                                                        |
|---------------|----------------------------------------------------------------|
| `searchOn()`  | Match the text against the given fields only                   |
| `highlight()` | Highlight matches in the given fields, or in all searched ones |
| `matching()`  | How query words must match: `last`, `all`, or `frequency`      |
| `threshold()` | Drop hits below a ranking score                                |
| `distinct()`  | Keep one hit per value of a field, for this search             |
| `options()`   | Pass any other Meilisearch search parameter                    |

## Federated search

Merge searches over several entity types into one list ranked across them:

```php
$results = Search::federated([
    Search::for(Product::class)->query($term)->weight(2),
    Search::for(Category::class)->query($term),
])->paginate(20);

foreach ($results->hits() as $hit) {
    $hit->entityType; // 'product'
    $hit->model;
}
```

Each search keeps its own filters, locale, and facets. Facets are keyed by entity type, then by name. The total is an estimate.

With Meilisearch, federated searches must agree on sorting: sort all of them by the same fields, or none.

## Index settings

With Meilisearch, searchable, filterable, and sortable fields, localized fields, and the distinct field are derived from the index definition. Typo tolerance is turned off for searchable fields that are not text, such as numbers.

Set anything else in `config/eav.php`, for all indexes or for one entity type:

```php
'search' => [
    'engines' => [
        'meilisearch' => [
            'settings' => [
                'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
                'stopWords' => ['the', 'a'],
                'pagination' => ['maxTotalHits' => 10000],
            ],
            'indexes' => [
                'product' => ['synonyms' => ['sneakers' => ['trainers']]],
            ],
        ],
    ],
],
```

Synonyms and stop words apply to an index as a whole, not per locale.

Settings are synced when an attribute, the entity types it is shared with, or a locale changes. Sync them yourself after changing the configuration or `searchIndex()`:

```bash
php artisan eav:sync-index-settings
php artisan eav:sync-index-settings product
```

Settings in `scout.meilisearch.index-settings` are still read, and the derived settings are restored after `scout:sync-index-settings`.

To rebuild the documents of an entity type:

```bash
php artisan eav:reindex product
php artisan eav:reindex product --flush
```

Run a queue worker for index updates.

## Filtering by a related entity's attribute

To filter by an attribute of a related entity, such as `category.color`, add the resolver in `config/eav.php`:

```php
'search' => [
    'resolvers' => [
        \Jurager\Eav\Search\AttributeRelationFilterResolver::class,
    ],
],
```

The resolver looks up the matching related records in the database and filters by their keys. Declare the key filterable in `searchIndex()`: the foreign key for a belongs-to relation, or `{relation}.{key}` for other relations. A filter matching more records than `eav.search.relation_limit` is rejected.

## Custom field types

A custom field is indexed as text. Override `indexType()` and `toIndex()` to change that:

```php
use Jurager\Eav\Enums\IndexType;

public function indexType(): IndexType
{
    return IndexType::Number;
}

public function toIndex(?int $localeId = null): mixed
{
    return $this->value($localeId)?->amount;
}
```

To index more than one field per attribute, override `indexFields()`. Each field computes its own value:

```php
use Jurager\Eav\Search\Index\Index;

public function indexFields(): array
{
    return [
        ...parent::indexFields(),
        $this->indexField(Index::LABELS, fn () => $this->value()?->formatted())->text()->searchable(),
    ];
}
```

Override `facetLabels()` to label facet values.

## Search engines

Searches run on the engine named by `eav.search.driver`, `meilisearch` by default. Indexing always goes through Scout, so your engine searches the index Scout's driver writes to.

To add an engine, implement `Jurager\Eav\Contracts\Search\Engine` and register it, usually in a service provider:

```php
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Response;

class TypesenseEngine implements Engine
{
    public function search(Builder $search, int $perPage, int $page): Response
    {
        // Translate $search->query, $search->filters, $search->orders, $search->facets ...
        // into a request, then return the hits, the total, and the facets.

        return new Response($hits, $total, $facets);
    }
}

Search::extend('typesense', fn ($app) => new TypesenseEngine($app->make(Client::class)));
```

Then set `EAV_SEARCH_DRIVER=typesense`.

The builder gives the engine names already checked against the index definition: use `$search->field($name)` and `$search->path($field)` to turn them into document paths. Return each facet keyed by the name it was asked for, as a map of value to count, or as `min` and `max` for a numeric or date field. The package adds labels and loads the models.

An engine may implement more contracts for what it can do natively. Without them the package falls back:

| Contract               | Native                                  | Fallback                                                      |
|------------------------|-----------------------------------------|---------------------------------------------------------------|
| `FederatesSearches`    | One request merging several searches    | Each search runs alone; hits are ranked by score times weight |
| `SearchesFacetValues`  | Searching the values of a facet         | The facet's values from a regular search, matched by substring |
| `UpdatesIndexSettings` | Syncing index settings from the definition | Nothing is synced                                          |

Federated search therefore works on any engine. The fallback asks each search for a whole page, so prefer a native implementation where the engine has one.

## Testing

`Search::fake()` answers searches with the hits you prepare instead of asking an engine, and records them:

```php
use Jurager\Eav\Search\Builder;

$search = Search::fake();
$search->respond('product', [$product->id], total: 1);

$this->get('/products?q=shoes')->assertOk();

$search->assertSearched(fn (Builder $search) => $search->query === 'shoes');
```

`respond()` takes the entity type, then keys or documents with an `id`. Pass `facets` to answer with facet data.
