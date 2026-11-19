---
title: Searching entities
weight: 83
---

The package indexes and searches entities with [Laravel Scout](https://laravel.com/docs/scout). `Product::search()` returns Scout's own builder, taught the entity's attributes: request filters, facets, per-locale search, boosting, and federated search across entity types.

Install Scout and a driver. With [Meilisearch](https://www.meilisearch.com), every feature on this page is available:

```bash
composer require laravel/scout meilisearch/meilisearch-php
```

Set `SCOUT_DRIVER=meilisearch`. Native federated search requires Meilisearch 1.10 or higher. [Other Scout drivers](#other-scout-drivers) search with what Scout's own query API can express.

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

Declare the model's own columns and computed values in `searchIndex()`. This one definition drives the document, the index settings, and the fields a request may filter and sort by:

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
| `keyed()`    | The value is a map, each key a field of its own at `path.{key}`      |

A keyed field holds one value per key, such as a price per price type or a stock per warehouse. Each key can be filtered, sorted, and faceted on by name:

```php
$index->field('prices')
    ->number()
    ->keyed()
    ->filterable()
    ->sortable()
    ->using(fn (Product $product) => $product->prices->pluck('amount', 'type')->all());

Product::search()->where('prices.retail', '<=', 500)->sort('prices.retail');
```

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

Search as you do with Scout. Everything Scout's builder offers still works: `paginate()`, `get()`, `first()`, `keys()`, `take()`, `raw()`, `withTrashed()`, and `query()` to customize how the models are loaded.

```php
$results = Product::search('красн кросовки')
    ->where('brand', 'nike')
    ->whereBetween('price', [1000, 5000])
    ->filter($request->query('filter'))
    ->sort('-price,name')
    ->facets(['brand', 'color', 'price'])
    ->query(fn ($query) => $query->with('media'))
    ->paginate(15);
```

Refer to a field by its path, an alias, or the attribute code. The labels of a select are the field `labels.{code}`.

`paginate()` returns a `LengthAwarePaginator` of models in ranking order, with the facets alongside:

```php
$results->items();   // models
$results->total();
$results->facets();
```

As in Scout, what the engine tells about a hit is on the model: `$product->scoutMetadata()['_rankingScore']`.

With Meilisearch the total is exact up to `pagination.maxTotalHits`, which is 1000 by default. Raise it in the [index settings](#index-settings) to page deeper.

`count()` returns the number of matches without loading anything.

A document whose entity no longer exists, for example after rows were deleted with a raw query, is removed from the index when a paginated search finds it. Set `eav.search.prune` to `false` to turn this off.

The text to search for goes to `search()`. As in Scout, `query()` takes a callback for the Eloquent query that loads the models.

### Locales

Text is matched against the searchable fields of the current locale. The engine is told the language, so typo tolerance and word splitting follow it. Pass other locales to `locale()`:

```php
Product::search('кроссовки')->locale('ru')->paginate();

Product::search('shoes')->locale('en', 'ru')->paginate();
```

Filters, sorts, and facets on a localizable field use the first locale.

A locale's Meilisearch language is the first two letters of its code. Map other codes in `eav.search.meilisearch.locales`. Searching in a locale that does not exist throws `InvalidSearchQueryException`.

Adding a locale syncs the index settings. Removing a locale or changing its code also rebuilds the documents that hold localized values, so no value stays indexed under a locale that is gone.

### Filters

`where()` accepts `=`, `!=`, `>`, `>=`, `<`, and `<=`; comparing with `null` asks whether the field holds a value. You can also use `whereIn()`, `whereNotIn()`, `whereBetween()`, `whereNotBetween()`, `whereNull()`, and `whereNotNull()`.

`filter()` accepts JSON:API filters as sent in a request, including `or` and `and` groups:

```php
Product::search()->filter([
    'color' => ['red', 'blue'],
    'price' => ['gte' => 100, 'lte' => 500],
    'released' => ['gte' => '2026-01-01'],
    'or' => [['brand' => 'nike'], ['brand' => 'puma']],
]);
```

Values are cast to the field's type, so dates can be sent as date strings and booleans as `true` or `1`.

Filters are strict. A field that is not filterable, an operator the field's type does not allow, or a value that does not fit throws `InvalidSearchQueryException` as soon as it is added, which renders as a 422. A condition is never dropped silently.

The `like` operator is rejected unless you set `eav.search.meilisearch.contains` to `true`. It compiles to Meilisearch's `CONTAINS` operator, an experimental feature you must enable on the server.

### Sorting

`sort()` accepts a JSON:API sort string. Sorting by a field that is not sortable throws `InvalidSearchQueryException`:

```php
Product::search()->sort('-price,name');

Product::search()->orderBy('price', 'desc');
```

To lead the results with the documents matching a condition, use `partition()`:

```php
Product::search()->partition(['in_stock' => true])->sort('price');
```

### Boosting

To rank some hits higher without filtering the rest out, boost a condition. The relevance of the hits matching it is multiplied by the weight:

```php
Product::search('phone')
    ->boost(['in_stock' => true], 3)
    ->boost(['brand' => 'apple'], 1.5)
    ->paginate();
```

A boost acts on relevance. It has no effect without a text query or when the search is sorted. To lead with a group whatever the order, use `partition()`; a search is either boosted or partitioned, not both.

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
Product::search()->where('category', 5)->facetValues('brand', 'ni');
```

### Highlighting and ranking

```php
$results = Product::search('leather')
    ->highlight('title')
    ->options(['matchingStrategy' => 'all', 'rankingScoreThreshold' => 0.3])
    ->paginate();

$product = $results->first();

$product->highlighted('title');                  // 'Red <em>leather</em> boots'
$product->scoutMetadata()['_rankingScore'];      // 0.87
```

`highlighted()` reads a field by the name the index definition gives it, in the current locale or the one you pass.

| Method        | Purpose                                                        |
|---------------|----------------------------------------------------------------|
| `searchOn()`  | Match the text against the given fields only                   |
| `highlight()` | Highlight matches in the given fields, or in all searched ones |
| `distinct()`  | Keep one hit per value of a field, for this search             |
| `options()`   | Scout's own: any other engine parameter, such as `matchingStrategy` |

As in Scout, a second call to `options()` replaces the first.

### Corrections

The engine puts up with typos, and when no document holds every word it searches for the first ones alone. Neither shows in the results, so a page for `jaket` looks like one for `jacket`. To tell the user what was searched for, ask for the correction:

```php
$results = Product::search('jaket')->correction()->paginate();

$results->correction();
// Correction {original: 'jaket', corrected: 'jacket', reason: CorrectionReason::Typo}
```

`reason` is `Typo` when the best hit holds the words written otherwise, and `Partial` when it holds only some of them. `corrected` is the text as the best hit writes the words, with the skipped ones left out; it is null when the highlights do not tell which word each one stands for. `correction()` is null when the text was found as written, and `toArray()` gives `original`, `corrected`, and `reason` as plain values.

The engine is asked for the ranking details and for highlights in every searched field. The correction is judged by the best hit of the whole list, whichever page you ask for: a page after the first asks the engine for that hit once more. In a federated search the best hit is the best across the types.

Filters narrow what is judged. If no document that passes them holds every word, the text is reported as `Partial`, though another document would hold them all.

A search without text has nothing to correct.

### Retyping

Text typed on the wrong keyboard layout, or in letters the documents do not use, finds nothing. With `retype()`, a search that finds nothing is run again for the text retyped, and the correction says so:

```php
$results = Product::search('irfa')->retype()->paginate();

$results->correction();
// Correction {original: 'irfa', corrected: 'шкаф', reason: CorrectionReason::Layout}
```

The texts are tried in turn, and the first that finds something wins, with `reason` and `corrected` the text found. If none finds anything, the results stay empty.

| Reason            | The text retyped                                                                                                      |
|-------------------|-----------------------------------------------------------------------------------------------------------------------|
| `Layout`          | The same keys on another layout, one text for each layout in `eav.search.layouts`: Latin letters become its letters, and its letters Latin ones |
| `Transliteration` | The letters written in Latin ones as `Str::ascii()` writes them (`самсунг` as `samsung`), which is how slugs are made |

The package knows no layout. List the ones you need under `eav.search.layouts`, each as its letters on the keys `` qwertyuiop[]asdfghjkl;'zxcvbnm,.` `` of a US keyboard, in that order:

```php
'layouts' => [
    'йцукенгшщзхъфывапролджэячсмитьбюё',
],
```

A layout needs a letter for each of the 33 keys. A text that would come out with signs instead of letters is not tried, nor is one that equals the text typed or another one tried. Each layout costs one more search when nothing is found. Transliteration needs no setup: it follows the language of the locale searched, and a language `Str::ascii()` has no rules for is written the English way.

A retyped search keeps the conditions, the sort, the facets, and the page. A federated search is run again as a whole, with the same text in every search that has it. Only `paginate()` retypes, and a text found as typed is never retyped.

## Federated search

Search several entity types at once and get one list ranked across them, such as products and services for the same query:

```php
use Jurager\Eav\Facades\Search;

$results = Search::federated([
    Product::search($term)->weight(2),
    Service::search($term),
])->paginate(20);

foreach ($results as $model) {
    $model->getEntityType(); // 'product' or 'service'
}
```

Each search runs over its own index and keeps its own filters, locale, boosts, and facets. `weight()` ranks the hits of one type above the others. The total is an estimate.

A federated search takes one search per entity type. To rank some hits of a type higher, `boost()` its search.

Facets are keyed by entity type, then by name, and describe every hit of that type. As in a regular search, a facet you filter on is counted without its own filter:

```php
$results->facets()['product']['color'];
```

A `partition()` on one of the searches leads across the whole list: its matching side, together with the searches that are not partitioned, comes before its other side.

Meilisearch rejects federated searches whose sort rules conflict. Give the searches the same sorting, or none.

## Index settings

Index settings belong to Scout. Configure typo tolerance, synonyms, stop words, and ranking rules in `config/scout.php`, by model class or index name:

```php
'meilisearch' => [
    'index-settings' => [
        Product::class => [
            'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
            'synonyms' => ['sneakers' => ['trainers']],
            'pagination' => ['maxTotalHits' => 10000],
        ],
    ],
],
```

The package adds what follows from the index definition: searchable, filterable, and sortable fields, localized fields, and the distinct field. Typo tolerance is turned off for searchable fields that are not text, such as numbers. You do not list these yourself.

Synonyms and stop words apply to an index as a whole, not per locale.

Settings are synced when an attribute, the entity types it is shared with, or a locale changes. Sync them yourself after changing the configuration or `searchIndex()`, with Scout's command:

```bash
php artisan scout:sync-index-settings
```

To rebuild the documents of an entity type, use Scout's commands as well:

```bash
php artisan scout:flush "App\Models\Product"
php artisan scout:import "App\Models\Product"
```

Run a queue worker for index updates.

## Filtering by a related entity's attribute

To filter by an attribute of a related entity, such as `category.color`, add the resolver in `config/eav.php`:

```php
'search' => [
    'resolvers' => [
        \Jurager\Eav\Search\Filters\RelationFilterResolver::class,
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

## Other Scout drivers

Entities are searched with the Scout driver they are indexed with. With Scout's Meilisearch driver the package uses its own engine, an extension of Scout's, for every model with attributes.

On any other driver a search uses Scout's own query API: text, `where()` with comparisons, `whereIn()`, `whereNotIn()`, `whereBetween()`, sorting, and pagination. Fields are still checked against the index definition.

What Scout cannot express there throws Scout's `NotSupportedException`: `or` groups, facets, `searchOn()`, `highlight()`, `correction()`, `boost()`, `partition()`, and facet search. Federated search still works: each search runs alone, and the hits are ranked by weight and rank.

Keep in mind that Scout's `database` and `collection` drivers search the model's columns, not the indexed document, so they do not see attribute values.

Scout's `null` driver, commonly used in tests, takes any search and finds nothing: facets, boosts, and the rest are accepted, and no index settings are synced.

A model that uses Scout's `Searchable` trait on its own, without `HasSearchableAttributes`, is left alone: the package does not touch its index settings.

To add these features to another engine, extend it as you extend any Scout engine and implement the package's contracts:

| Contract                 | The engine can                                                    |
|--------------------------|-------------------------------------------------------------------|
| `SupportsExtendedSearch` | Answer groups of conditions, facets, locales, boosts, partitions  |
| `FederatesSearches`      | Merge several searches into one ranked list by itself             |
| `DerivesIndexSettings`   | Derive its index settings from the index definition               |

An engine receives searches already checked against the index definition: `$search->conditions` are filterable fields with a `Comparison` and a value of the field's type, `$search->sorts` are sortable fields. `Search\Engines\MeilisearchEngine` is the reference.

## Testing

`Search::fake()` swaps Scout's driver for one that answers searches with the hits you prepare and records them. Indexing does nothing:

```php
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Builder;

$search = Search::fake();
$search->respond('product', [$product->id], total: 1);

$this->get('/products?q=shoes')->assertOk();

$search->assertSearched(fn (Builder $search) => $search->query === 'shoes');
```

`respond()` takes the entity type, then keys or documents with an `id`. Pass `facets` to answer with facet data, and `text` to answer only the searches for that text. The keys of a document that start with `_` become Scout metadata of its model, so a hit can carry `_rankingScore`, or the `_rankingScoreDetails` and `_formatted` a correction is read from.
