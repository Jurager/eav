---
title: Upgrade guide
weight: 95
---

## Upgrading to 1.0.7 from 1.0.3 or earlier

Search was rebuilt on Laravel Scout's own builder, around one index definition per model. The public search API changed, and the search document has a new shape, so indexes must be rebuilt.

These are breaking changes released as patch versions. A `^1.0` constraint installs them on `composer update`, so follow this guide before updating a project. If a project already runs 1.0.4, 1.0.5 or 1.0.6, read [Upgrading to 1.0.7 from 1.0.4 – 1.0.6](#upgrading-to-107-from-104--106) instead.

Estimated time: 30 minutes, plus the time to reindex.

### Requirements

- `laravel/scout` and `meilisearch/meilisearch-php` 1.16 or higher
- Meilisearch 1.10 or higher for federated search

### Before you deploy

Let the queue drain, or deploy with the workers stopped. Queued jobs are stored by class name, and `Jobs\SyncSearchable` no longer exists: a job queued by the old version fails when the new one picks it up. The reindex in the last step makes up for any index update lost this way.

### 1. Run the migration

A new `sortable` flag was added to attributes and attribute types:

```bash
php artisan migrate
```

If you published the package migrations, publish them again first:

```bash
php artisan vendor:publish --tag=eav-migrations
```

Attribute types that are `filterable` become `sortable` too. Enable the flag on other types that should allow it.

Set `sortable` on the attributes you sort search results by. Attribute paths previously listed in `indexFields()` with `IndexCapability::Sort` are the ones to flag.

`Field::toMetadata()` now includes a `sortable` key, so API responses built from it gain one.

### 2. Update searchable models

`HasSearchableAttributes` now includes Scout's `Searchable` trait and no longer needs conflict resolution:

```php
// Before
use HasAttributes, HasSearchableAttributes, Searchable {
    HasSearchableAttributes::toSearchableArray insteadof Searchable;
    HasSearchableAttributes::shouldBeSearchable insteadof Searchable;
}

// After
use HasAttributes, HasSearchableAttributes;
```

Replace the methods that described the index with one `searchIndex()` method:

| Removed                                       | Replacement in `searchIndex()`                       |
|-----------------------------------------------|------------------------------------------------------|
| `toSearchableArray()` override                | `$index->field('sku')`, with `using()` for computed values |
| `searchableColumns()`                         | Derived from the declared fields; `watch()` for computed ones |
| `searchableWith()`                            | `$index->field(...)->with('relation')` or `$index->with()` |
| `InteractsWithIndex::indexFields()`           | `->filterable()` and `->sortable()` on each field    |
| `InteractsWithIndex::indexAliases()`          | `->alias('name')` on the field                       |
| An `indexFields()` path covering nested keys, such as `prices` for `prices.retail` | `->keyed()` on the field |
| `Builder::map()`                              | `->alias('name')` on the field                       |

```php
// Before
public function toSearchableArray(): array
{
    return ['id' => (string) $this->getScoutKey(), 'sku' => $this->sku, ...$this->eav()->indexData()];
}

public function indexFields(): array
{
    return ['sku' => [IndexCapability::Filter], 'attributes.price' => [IndexCapability::Sort]];
}

public function indexAliases(): array
{
    return ['categories.category_id' => 'categories.id'];
}

// After
public function searchIndex(Index $index): void
{
    $index->field('sku')->keyword()->searchable()->filterable();

    $index->field('categories.id')
        ->number()
        ->using(fn (self $product) => $product->categories->modelKeys())
        ->with('categories')
        ->filterable()
        ->alias('categories.category_id');
}
```

Remove the `InteractsWithIndex` interface from your models. `shouldBeSearchable()` is no longer overridden: an entity without indexed attribute values is indexed too. Override it yourself to exclude entities.

Only fields declared `searchable` are matched by full-text search. Previously every field of the document was. Declare the columns you search by.

### 3. Update search calls

`SearchFactory` is gone. Search with Scout's own entry point, `Model::search()`:

```php
// Before
$result = app(SearchFactory::class)->for('product')
    ->query($term)
    ->filter($filter)
    ->facets(['color', 'attributes.price'])
    ->sort('-price')
    ->search($perPage, $page);

$products = $result->paginate(Product::class, $perPage, $page);
$facets = $result->facets;

// After
$products = Product::search($term)
    ->filter($filter)
    ->facets(['color', 'price'])
    ->sort('-price')
    ->paginate($perPage, 'page', $page);

$facets = $products->facets();
```

| Before                              | After                                              |
|-------------------------------------|----------------------------------------------------|
| `->for('product')->query($term)`    | `Product::search($term)`                           |
| `->search($perPage, $page)`         | `->paginate($perPage, 'page', $page)`, as in Scout |
| `$result->ids`                      | `->keys()` on the builder, or `$results->modelKeys()` |
| `$result->total`                    | `$results->total()`                                |
| `$result->facets`                   | `$results->facets()`                               |
| `$result->paginate($model, ...)`    | `paginate()` already returns the models            |
| `->orderBy('attributes.price')`     | `->orderBy('price')`                               |
| `->hasFilter()`, `->ids()`, `->map()`, getters | Removed                                 |

The builder is Scout's, so its methods mean what they mean in Scout. In particular `query()` takes a callback for the Eloquent query that loads the models; the text goes to `search()`.

`filter()` now adds to the conditions already set instead of replacing them.

With Meilisearch the total is exact up to `pagination.maxTotalHits` (1000 by default). It was an estimate.

### 4. Update code that reads facets

Facets are keyed by the name you asked for, not by index path, and have one shape:

```php
// Before
$facets['attributes']['color'];        // ['red' => ['count' => 3, 'label' => 'Red']]
$facets['attributes']['price'];        // only for keys containing a dot: ['min' => 1, 'max' => 9]

// After
$facets['color'];   // ['type' => 'terms', 'values' => [['value' => 'red', 'label' => 'Red', 'count' => 3]]]
$facets['price'];   // ['type' => 'range', 'min' => 1, 'max' => 9]
```

Numeric and date fields give a range. You no longer mark a range facet with a dot in its key.

### 5. Handle strict filters and sorts

Requests that were silently relaxed now fail with a 422 (`InvalidSearchQueryException`):

- a filter on a field that is not in the index or not filterable
- an operator the engine cannot answer: `like` and `tree`
- a range operator (`gt`, `gte`, `lt`, `lte`, `between`, `not_between`) on a field that is not a number or a date, such as a select
- a value that does not fit the field's type
- a sort by a field that is not sortable; an unknown sort field was ignored before

The exception is thrown when the filter or sort is added to the builder, not when the search runs. Check the filters and sorts your clients send. To keep `like`, set `eav.search.meilisearch.contains` to `true` and enable the `containsFilter` experimental feature in Meilisearch.

Date filters now work with date strings. Dates are indexed as timestamps, and the old compiler dropped date conditions that were not numeric.

Sort resolvers of `jurager/filterable` are no longer consulted for search. Sorting uses the index definition.

Trashed entities are left out of the results when `scout.soft_delete` is on, as in Scout. Use `withTrashed()` to include them.

### 6. Read option labels by locale

The registry of select options now holds their labels in every locale. It used to hold only the locale active when it was first loaded, which gave the wrong labels in long-running workers.

Code that relied on that, such as taking the first translation of an option, must ask for a locale:

```php
// Before
$enum->translations->first()?->pivot->label;

// After
$enum->label($localeId);
```

### 7. Opt in to relation filters

Filtering by an attribute of a related entity, such as `category.color`, is off by default. To keep it, add the resolver to `config/eav.php`:

```php
'search' => [
    'resolvers' => [
        \Jurager\Eav\Search\Filters\RelationFilterResolver::class,
    ],
],
```

For a belongs-to relation the resolver now filters by the foreign key itself (`category_id`) instead of `category.category_id`. Declare that field filterable in `searchIndex()`. A filter matching more than `eav.search.relation_limit` related records is rejected.

The `eav.search.resolvers` container tag is no longer used.

### 8. Move index settings

Index settings belong to Scout. Keep typo tolerance, synonyms, stop words, and ranking rules in `scout.meilisearch.index-settings`, and remove `filterableAttributes`, `sortableAttributes`, and `searchableAttributes` from there: they are derived from `searchIndex()` and the attribute flags.

Publish the package configuration again, or add the `search` section from [config/eav.php](https://github.com/Jurager/eav/blob/main/config/eav.php).

### 9. Update custom fields

| Removed                       | Replacement                                             |
|-------------------------------|---------------------------------------------------------|
| `filterableKeys()`            | `indexFields()`                                         |
| `indexData()`                 | `toIndex()`, or a value closure on a field of `indexFields()` |
| `enrichFacetDistribution()`   | `facetLabels(array $values, int $localeId)`             |
| `$entity->eav()->indexData()` | `$entity->toSearchableArray()`                          |

Most fields only need `indexType()` and `toIndex()`. The document is built from `indexFields()`, so a field no longer returns its index data separately.

### 10. Update other references

| Removed                                   | Replacement                                    |
|-------------------------------------------|------------------------------------------------|
| `Schema::search()`                        | Removed. Use Scout on your own attribute model |
| `SearchFactory`, `Search\Engine`, `Search\Compiler` | `Model::search()`; the engine is Scout's       |
| `Jobs\SyncSearchable`                     | `Jobs\ReindexAttribute`                        |
| `SchemaSynchronizer::refresh($attribute, documents:, settings:)` | `refresh($attribute, indexed:, entityTypes:)` |
| `Search\Indexer::refresh($type)->ids($ids)` | `app(Search\Index\Indexer::class)->ids($type, $ids)` |
| `EntityValues::indexable()`               | Removed                                        |
| `Observers\SyncsIndexFilters::watch()`    | Dispatch `Jobs\SyncIndexSettings` yourself     |
| `Search\Result`                           | `Search\Results`                               |
| `Enums\IndexCapability`                   | `->filterable()` and `->sortable()` on fields  |
| `SearchNotAvailableException::scoutNotInstalled()`, `meilisearchNotInstalled()` | `notInstalled()`, `notSearchable()` |

### 11. Update renamed classes

Classes outside search were moved to follow Laravel's layout. Update your imports:

| Before                                                  | After                                              |
|---------------------------------------------------------|----------------------------------------------------|
| `Jurager\Eav\Enums\AttributeType`                       | `Jurager\Eav\Enums\FieldType`                      |
| `Jurager\Eav\Registry\*`                                | `Jurager\Eav\Registries\*`                         |
| `Jurager\Eav\Scopes\ActiveLocaleScope`                  | `Jurager\Eav\Models\Scopes\ActiveLocaleScope`      |
| `Jurager\Eav\Attributes\Scope\AttributeInheritanceResolver` | `Jurager\Eav\Attributes\AttributeInheritanceResolver` |
| `Jurager\Eav\Attributes\Validation\AttributeValidator`  | `Jurager\Eav\Attributes\AttributeValidator`         |
| `Jurager\Eav\Commands\ClearCommand`                     | `Jurager\Eav\Console\ClearCommand`                  |
| `Jurager\Eav\Search\Contracts\FilterResolver`           | `Jurager\Eav\Contracts\Search\FilterResolver`       |
| `Jurager\Eav\Search\Resolvers\AttributeRelationFilterResolver` | `Jurager\Eav\Search\Filters\RelationFilterResolver` |

The `eav:clear` command keeps its name.

### 12. Rebuild the indexes

The document changed: localizable values are keyed by locale code (`attributes.title.en`), and select labels moved from `attributes.{code}_label` to `labels.{code}`, stored per locale. Old documents do not match the new settings.

Sync the settings, then rebuild each searchable model with Scout's commands:

```bash
php artisan scout:sync-index-settings
php artisan scout:flush "App\Models\Product"
php artisan scout:import "App\Models\Product"
```

Run a queue worker if Scout queues indexing. Search returns partial results until the import finishes, so run it in a maintenance window or against a new index prefix (`SCOUT_PREFIX`).

After deploying, `scout:sync-index-settings` is enough when only the configuration or `searchIndex()` changed.

Two things change how the index behaves from here on:

- An entity without indexed attribute values is indexed too, so an index may hold more documents than before.
- A paginated search that finds a document whose entity no longer exists removes it from the index. Set `eav.search.prune` to `false` to turn this off.

## Upgrading to 1.0.7 from 1.0.4 – 1.0.6

1.0.7 moves search onto Scout's own builder. The package no longer has search engines of its own: entities are searched with the Scout driver they are indexed with. The search document did not change, so no reindex is needed.

### Search calls

`Search::for()` is gone. `Model::search()` returns Scout's builder with the same methods on top:

```php
// Before
Search::for(Product::class)->query($term)->where('brand', 'nike')->paginate(15, 2);

// After
Product::search($term)->where('brand', 'nike')->paginate(15, 'page', 2);
```

| Before                                   | After                                                    |
|------------------------------------------|----------------------------------------------------------|
| `Search::for(Product::class)`            | `Product::search()`                                      |
| `->query($term)`                         | `Product::search($term)`                                 |
| `->paginate($perPage, $page)`            | `->paginate($perPage, 'page', $page)`                    |
| `->get($limit)`                          | `->take($limit)->get()`                                  |
| `->keys($limit)`, an array               | `->take($limit)->keys()`, a collection                   |
| `->with('media')`, `->loadUsing($callback)` | `->query(fn ($query) => $query->with('media'))`       |
| `->matching('all')`, `->threshold(0.3)`  | `->options(['matchingStrategy' => 'all', 'rankingScoreThreshold' => 0.3])` |
| `$results->hits()`, `$hit->score`        | `$model->scoutMetadata()['_rankingScore']`               |
| `$hit->highlight('title')`               | `$model->highlighted('title')`                           |
| `$hit->entityType` in a federated search | `$model->getEntityType()`                                |
| `->whereNot('color', 'red')`             | `->where('color', '!=', 'red')`                          |
| `Search::federated([...])->paginate(20, 2)` | `Search::federated([...])->paginate(20, 'page', 2)`   |

`query()` now means what it means in Scout: a callback for the Eloquent query that loads the models. Passing it text throws. `options()` is Scout's too: a second call replaces the first instead of adding to it.

`Search::federated()` takes one search per entity type and throws for two searches over one type. To rank some hits of a type higher, use `boost()` on its search.

### Configuration and commands

| Before                                              | After                                              |
|-----------------------------------------------------|----------------------------------------------------|
| `eav.search.driver`, `EAV_SEARCH_DRIVER`            | Removed: Scout's driver is the driver              |
| `eav.search.settings`, `eav.search.engines.meilisearch.settings` and `indexes` | `scout.meilisearch.index-settings` |
| `eav.search.locales`, `eav.search.engines.meilisearch.locales`   | `eav.search.meilisearch.locales`      |
| `eav.search.contains`, `eav.search.engines.meilisearch.contains` | `eav.search.meilisearch.contains`     |
| `php artisan eav:sync-index-settings` (`eav:search:sync`) | `php artisan scout:sync-index-settings`      |
| `php artisan eav:reindex product` (`eav:search:reindex`)  | `php artisan scout:import "App\Models\Product"` |

Move the settings you kept under `eav.search` to `config/scout.php`, by model class or index name.

### Tests

`Search::fake()` swaps Scout's driver for a fake one:

```php
$search = Search::fake();
$search->respond('product', [$product->id]);
$search->assertSearched(fn (Builder $search) => $search->query === 'shoes');
```

`respond()` takes the entity type, and `assertSearched()` receives the builder. In 1.0.4 they took the index name and a Meilisearch request array.

### Removed classes

| Removed                                                        | Replacement                                    |
|----------------------------------------------------------------|------------------------------------------------|
| `Search\Engine`, `Search\Engines\MeilisearchEngine` as a driver of the package | `Search\Engines\MeilisearchEngine`, an extension of Scout's engine |
| `Contracts\Search\Engine`, `BoostsMatches`, `SearchesFacetValues`, `UpdatesIndexSettings` | `Contracts\Search\SupportsExtendedSearch`, `FederatesSearches`, `DerivesIndexSettings` |
| `Search::extend($driver, ...)`, `Search::driver()`              | Scout's own `EngineManager::extend()`          |
| `Search\RequestFactory`, `Search\FilterCompiler`, `Search\Index\Settings` | `Search\Grammars\MeilisearchGrammar` |
| `Search\Hit`, `Results::hits()`, `Results::keys()`              | Scout metadata on the models                   |
| `Search\AttributeRelationFilterResolver`                        | `Search\Filters\RelationFilterResolver`        |
| `Search\Testing\FakeEngine`                                    | `Search\Engines\FakeEngine`                    |
| `Contracts\Search\Transport`, `Search\Transport\*`            | Removed                                        |
| `Console\SyncIndexSettingsCommand`, `Console\ReindexCommand`   | Scout's commands                               |

`Builder::$filters` is now `Builder::$conditions` and `Builder::$orders` is `Builder::$sorts`; both hold checked conditions and index fields instead of raw request data. `Builder::$index` is Scout's index name; the index definition is `Builder::$definition`.
