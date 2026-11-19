<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Contracts\Search\SupportsExtendedSearch;
use Jurager\Eav\Search\Engines\MeilisearchEngine;
use Jurager\Eav\Search\Index\IndexFactory;
use Jurager\Eav\Search\Results;
use Jurager\Eav\Tests\Fixtures\PlainSearchable;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine as ScoutMeilisearchEngine;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Exceptions\NotSupportedException;
use Meilisearch\Client;

/**
 * Search as it is used: through Scout's own entry point, on whichever engine Scout is configured with.
 */
class SearchTest extends SearchTestCase
{
    public function test_search_returns_scouts_builder_taught_the_index_definition(): void
    {
        $search = SearchableProduct::search('shoes');

        $this->assertInstanceOf(Builder::class, $search);
        $this->assertInstanceOf(\Laravel\Scout\Builder::class, $search);
        $this->assertSame('searchable_product', $search->definition->entityType);
        $this->assertInstanceOf(MeilisearchEngine::class, $search->engine());
    }

    public function test_text_given_to_query_points_to_search(): void
    {
        $this->expectExceptionMessage('Pass the text to search()');

        SearchableProduct::search()->query('shoes');
    }

    public function test_conditions_are_checked_and_cast_whatever_the_engine(): void
    {
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);
        $this->attribute('date', 'released', ['filterable' => true]);

        $search = SearchableProduct::search()
            ->filter(['price' => ['gte' => '10'], 'released' => ['lt' => '2026-01-01']])
            ->whereIn('parent', ['1', '2'])
            ->where('parent', null)
            ->sort('-price');

        [$price, $released] = $search->conditions[0]->members;
        [$parent] = $search->conditions[1]->members;

        $this->assertSame(['attributes.price', Comparison::GreaterOrEqual, 10], [$price->field->path, $price->comparison, $price->value]);
        $this->assertSame([Comparison::Less, strtotime('2026-01-01')], [$released->comparison, $released->value]);
        $this->assertSame(['parent_id', Comparison::In, [1, 2]], [$parent->field->path, $parent->comparison, $parent->value]);
        $this->assertSame(Comparison::Missing, $search->conditions[2]->members[0]->comparison);
        $this->assertSame(['attributes.price', 'desc'], [$search->sorts[0][0]->path, $search->sorts[0][1]]);
    }

    public function test_a_request_is_refused_before_it_reaches_the_engine(): void
    {
        $this->attribute('text', 'sku', ['searchable' => true]);

        $attempts = [
            fn ($search) => $search->filter(['nope = 1 OR id > 0 OR nope' => 1]),
            fn ($search) => $search->filter(['sku' => 'x']),
            fn ($search) => $search->where('parent', 'not a number'),
            fn ($search) => $search->orderBy('sku'),
            fn ($search) => $search->latest(),
            fn ($search) => $search->facets('sku'),
            fn ($search) => $search->searchOn('parent'),
            fn ($search) => $search->distinct('sku'),
            fn ($search) => $search->highlight('nope'),
            fn ($search) => $search->locale('de'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt(SearchableProduct::search())->paginate();
                $this->fail('The request should have been refused.');
            } catch (InvalidSearchQueryException) {
                // Refused, as it should be.
            }
        }

        $this->assertSame([], $this->engine->searches);
    }

    public function test_an_engine_that_only_knows_scout_gets_conditions_through_scouts_own_wheres(): void
    {
        config(['scout.driver' => 'collection']);
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);

        $search = SearchableProduct::search('shoes')
            ->where('price', '>=', 10)
            ->whereBetween('price', [1, 5])
            ->whereIn('parent', [1, 2])
            ->whereNull('parent')
            ->orderBy('price', 'desc');

        $this->assertNotInstanceOf(SupportsExtendedSearch::class, $search->engine());
        $this->assertSame([
            ['field' => 'attributes.price', 'operator' => '>=', 'value' => 10],
            ['field' => 'attributes.price', 'operator' => '>=', 'value' => 1],
            ['field' => 'attributes.price', 'operator' => '<=', 'value' => 5],
            ['field' => 'parent_id', 'operator' => '=', 'value' => null],
        ], $search->wheres);
        $this->assertSame(['parent_id' => [1, 2]], $search->whereIns);
        $this->assertSame([['column' => 'attributes.price', 'direction' => 'desc']], $search->orders);
    }

    public function test_an_engine_that_only_knows_scout_refuses_what_scout_cannot_express(): void
    {
        config(['scout.driver' => 'collection']);
        $this->attribute('number', 'price', ['filterable' => true]);

        $attempts = [
            fn ($search) => $search->facets('price'),
            fn ($search) => $search->filter(['or' => [['price' => 1], ['parent' => 2]]]),
            fn ($search) => $search->whereNotBetween('price', [1, 2]),
            fn ($search) => $search->boost(['price' => 1]),
            fn ($search) => $search->partition(['price' => 1]),
            fn ($search) => $search->searchOn('name'),
            fn ($search) => $search->highlight('name'),
            fn ($search) => $search->correction(),
            fn ($search) => $search->facetValues('price'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt(SearchableProduct::search('x'));
                $this->fail('The engine should not be asked for what it cannot do.');
            } catch (NotSupportedException $e) {
                $this->assertStringContainsString('search engine in use cannot', $e->getMessage());
            }
        }
    }

    public function test_scouts_meilisearch_engine_is_used_in_its_extended_form(): void
    {
        $this->app->make(EngineManager::class)->forgetDrivers();
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => new ScoutMeilisearchEngine(new Client('http://127.0.0.1:1')));

        $engine = (new SearchableProduct)->searchableUsing();

        $this->assertSame(MeilisearchEngine::class, $engine::class);
        $this->assertSame($engine, SearchableProduct::search()->engine(), 'One engine indexes and searches.');
    }

    public function test_scouts_null_driver_takes_any_search_and_finds_nothing(): void
    {
        config(['scout.driver' => null]);
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);
        $this->product();

        $search = fn () => SearchableProduct::search('shoes')
            ->filter(['or' => [['price' => ['gt' => 1]], ['parent' => 2]]])
            ->facets('price')
            ->highlight('name')
            ->correction()
            ->sort('-price');

        $this->assertInstanceOf(NullEngine::class, $search()->engine());

        $results = $search()->partition(['price' => 1])->paginate();

        $this->assertCount(0, $results);
        $this->assertSame(0, $results->total());
        $this->assertSame([], $results->facets());
        $this->assertNull($results->correction());
        $this->assertSame(0, $search()->boost(['price' => 1])->count());
        $this->assertSame([], $search()->facetValues('price', '1'));
        $this->assertCount(0, Search::federated([$search(), SearchableCategory::search('shoes')])->paginate());
        $this->assertSame([], $this->engine->searches);
    }

    public function test_index_settings_are_not_synced_under_scouts_null_driver(): void
    {
        config(['scout.driver' => null]);
        $this->attribute('number', 'price', ['filterable' => true]);

        $this->app->call([new SyncIndexSettings('searchable_product'), 'handle']);

        $this->assertSame([], $this->engine->settings);
    }

    public function test_a_model_searched_with_scout_alone_keeps_its_index_settings(): void
    {
        Relation::morphMap(['plain_searchable' => PlainSearchable::class]);

        $this->assertNotContains('plain_searchable', app(IndexFactory::class)->entityTypes());

        $this->app->call([new SyncIndexSettings('plain_searchable'), 'handle']);

        // Told to follow Scout's own settings command, the package still leaves the model alone.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('scout:sync-index-settings')->assertSuccessful();

        $this->assertArrayNotHasKey('eav_test_plain', $this->engine->settings);
        $this->assertArrayHasKey('eav_test_products', $this->engine->settings);
        $this->assertSame(\Laravel\Scout\Builder::class, PlainSearchable::search('x')::class);
    }

    public function test_fake_answers_searches_and_records_them(): void
    {
        $fake = Search::fake();
        $product = $this->product('Shoes');

        $fake->assertNothingSearched();
        $fake->respond('searchable_product', [['id' => $product->id, 'name' => 'Shoes', '_rankingScore' => 0.8]], total: 9);

        $results = SearchableProduct::search('shoes')->where('parent', null)->locale('ru')->paginate(5);

        $this->assertInstanceOf(Results::class, $results);
        $this->assertTrue($results->first()->is($product));
        $this->assertSame(9, $results->total());
        $this->assertSame(0.8, $results->first()->scoutMetadata()['_rankingScore']);
        $this->assertTrue(SearchableProduct::search('shoes')->first()->is($product));
        $this->assertSame([], $this->engine->searches, 'Meilisearch is not asked.');

        $fake->assertSearched(fn (Builder $search) => $search->query === 'shoes' && $search->locales() === ['ru']);
    }

    public function test_fake_facets_get_labels_and_one_shape(): void
    {
        $fake = Search::fake();
        $color = $this->attribute('select', 'color', ['filterable' => true]);
        $this->option($color, 'red', ['en' => 'Red']);
        $this->attribute('number', 'price', ['filterable' => true]);

        $fake->respond('searchable_product', [], facets: ['color' => ['red' => 2, 'dark-red' => 4, 'blue' => 1], 'price' => ['min' => 5, 'max' => 9]]);

        $search = fn () => SearchableProduct::search()->facets(['color', 'price']);

        $this->assertSame(
            ['type' => 'terms', 'values' => [
                ['value' => 'red', 'label' => 'Red', 'count' => 2],
                ['value' => 'dark-red', 'label' => 'dark-red', 'count' => 4],
                ['value' => 'blue', 'label' => 'blue', 'count' => 1],
            ]],
            $search()->paginate()->facet('color'),
        );
        $this->assertSame(['type' => 'range', 'min' => 5, 'max' => 9], $search()->paginate()->facet('price'));
        $this->assertSame(['red', 'dark-red'], array_column($search()->facetValues('color', 'RED'), 'value'));
    }

    public function test_an_engine_that_cannot_federate_has_its_searches_merged_by_weighted_score(): void
    {
        $fake = Search::fake();
        $this->attribute('select', 'color', ['filterable' => true]);

        $shoes = $this->product('Shoes');
        $boots = $this->product('Boots');
        $footwear = SearchableCategory::create(['name' => 'Footwear']);

        $fake->respond('searchable_product', [
            ['id' => $shoes->id, '_rankingScore' => 0.9],
            ['id' => $boots->id, '_rankingScore' => 0.4],
        ], facets: ['color' => ['red' => 1]]);
        $fake->respond('searchable_category', [['id' => $footwear->id, '_rankingScore' => 0.5]]);

        $federated = fn () => Search::federated([
            SearchableProduct::search('x')->facets('color'),
            SearchableCategory::search('x')->weight(2),
        ]);

        // 0.5 × 2 outranks 0.9; 0.4 comes last.
        $results = $federated()->paginate(2);

        $this->assertSame(['Footwear', 'Shoes'], $results->getCollection()->pluck('name')->all());
        $this->assertSame(['searchable_category', 'searchable_product'], $results->getCollection()->map->getEntityType()->all());
        $this->assertSame(3, $results->total());
        $this->assertSame('red', $results->facets()['searchable_product']['color']['values'][0]['value']);

        $this->assertSame(['Boots'], $federated()->paginate(2, 'page', 2)->getCollection()->pluck('name')->all());
        $this->assertSame(['Footwear', 'Shoes', 'Boots'], $federated()->get()->pluck('name')->all());
    }

    public function test_a_federated_search_takes_one_search_per_entity_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one search per entity type');

        Search::federated([SearchableProduct::search('a'), SearchableProduct::search('b')]);
    }

    public function test_federating_nothing_finds_nothing(): void
    {
        $this->assertCount(0, Search::federated([])->paginate());
        $this->assertSame([], $this->engine->searches);
    }

    public function test_index_settings_are_left_alone_on_an_engine_that_keeps_none(): void
    {
        config(['scout.driver' => 'collection']);

        $this->app->call([new SyncIndexSettings('searchable_product'), 'handle']);

        $this->assertSame([], $this->engine->settings);
    }
}
