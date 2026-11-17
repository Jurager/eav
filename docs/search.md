---
title: Searching with Meilisearch
weight: 83
---

The package indexes attribute values with [Laravel Scout](https://laravel.com/docs/scout) and searches them with [Meilisearch](https://www.meilisearch.com): full-text search with typo tolerance, per-locale search, filters, facets, sorting, and federated search across entity types.

Install Scout and the Meilisearch client, and set Scout's driver to `meilisearch`:

```bash
composer require laravel/scout meilisearch/meilisearch-php
```

Federated search requires Meilisearch 1.10 or higher.

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

An attribute with none of these flags is not indexed.

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

A save reindexes the entity only when a declared column changed. Attribute value changes are indexed after commit, including the variants that inherit them.

## The search document

Attribute values are nested under `attributes`. A localizable value is keyed by locale code, and a select is indexed by option code with its labels alongside:

```json
{
    "id": 1,
    "sku": "RS-100",
    "attributes": {
        "price": 100,
        "title": {"en": "Red running shoes", "ru": "Красные кроссовки"},
        "color": "red",
        "color_label": {"en": "Red", "ru": "Красный"},
        "released": 1767999600
    }
}
```

Dates are indexed as Unix timestamps.

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

`Search::for()` accepts a model, its class, or its morph alias. Refer to a field by its path, an alias, or the attribute code.

`paginate()` returns a `LengthAwarePaginator` of models in ranking order, with an exact total:

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

Use `with()` to eager load relations on the models, or `hydrate()` to change the query they are loaded with:

```php
Search::for(Product::class)
    ->query('shoes')
    ->with('media')
    ->hydrate(fn ($query) => $query->where('published', true))
    ->paginate();
```

### Locales

Text is matched against the searchable fields of the current locale. Meilisearch is told the language, so typo tolerance and word splitting follow it. Pass other locales to `locale()`:

```php
Search::for(Product::class)->query('кроссовки')->locale('ru')->paginate();

Search::for(Product::class)->query('shoes')->locale('en', 'ru')->paginate();
```

Filters, sorts, and facets on a localizable field use the first locale.

A locale's language is the first two letters of its code. Map other codes in `eav.search.locales`.

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

The `like` operator is rejected unless you set `eav.search.contains` to `true`. It compiles to Meilisearch's `CONTAINS` operator, an experimental feature you must enable on the server.

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

Each search keeps its own filters, locale, and facets. Facets are keyed by entity type, then by name. The total is Meilisearch's estimate.

## Index settings

Searchable, filterable, and sortable fields, localized fields, and the distinct field are derived from the index definition. Typo tolerance is turned off for searchable fields that are not text, such as numbers.

Set anything else in `config/eav.php`, for all indexes or for one entity type:

```php
'search' => [
    'settings' => [
        'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
        'stopWords' => ['the', 'a'],
        'pagination' => ['maxTotalHits' => 10000],
    ],
    'indexes' => [
        'product' => ['synonyms' => ['sneakers' => ['trainers']]],
    ],
],
```

Synonyms and stop words apply to an index as a whole, not per locale.

Settings are synced when an attribute or a locale changes. Sync them yourself after changing the configuration or `searchIndex()`:

```bash
php artisan eav:search:sync
php artisan eav:search:sync product
```

Settings in `scout.meilisearch.index-settings` are still read, and the derived settings are restored after `scout:sync-index-settings`.

To rebuild the documents of an entity type:

```bash
php artisan eav:search:reindex product
php artisan eav:search:reindex product --flush
```

Run a queue worker for index updates.

## Filtering by a related entity's attribute

To filter by an attribute of a related entity, such as `category.color`, add the resolver in `config/eav.php`:

```php
'search' => [
    'resolvers' => [
        \Jurager\Eav\Search\Resolvers\AttributeRelationFilterResolver::class,
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

Override `indexFields()` and `indexData()` to index more than one field per attribute, and `facetLabels()` to label facet values.

## Testing

`Search::fake()` records requests instead of sending them, and answers with the hits you prepare:

```php
$engine = Search::fake();
$engine->respond('products', [['id' => $product->id]], total: 1);

$this->get('/products?q=shoes')->assertOk();

$engine->assertSearched(fn (array $request) => $request['q'] === 'shoes');
```
