<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Engines\MeilisearchEngine;
use Jurager\Eav\Search\Testing\FakeEngine;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\Jobs\MakeSearchable;

class SearchManagerTest extends SearchTestCase
{
    /**
     * Register an engine that only searches, and make it the driver.
     */
    private function useCustomEngine(): FakeEngine
    {
        $engine = new FakeEngine;

        Search::extend('custom', fn () => $engine);
        config(['eav.search.driver' => 'custom']);

        return $engine;
    }

    public function test_meilisearch_is_the_default_driver(): void
    {
        $this->assertInstanceOf(MeilisearchEngine::class, Search::driver());
        $this->assertSame(Search::driver(), Search::driver('meilisearch'));
    }

    public function test_a_driver_that_is_not_registered_is_refused(): void
    {
        config(['eav.search.driver' => 'nope']);

        $this->expectException(InvalidArgumentException::class);

        Search::for(SearchableProduct::class);
    }

    public function test_searches_run_on_a_registered_engine(): void
    {
        $engine = $this->useCustomEngine();
        $product = $this->product('Shoes');
        $engine->respond('searchable_product', [['id' => $product->id, 'name' => 'Shoes', '_score' => 0.8]], total: 9);

        $results = Search::for(SearchableProduct::class)->query('shoes')->where('parent', 1)->paginate(5);

        $this->assertTrue($results->first()->is($product));
        $this->assertSame(9, $results->total());
        $this->assertSame(0.8, $results->hits()->first()->score);
        $this->assertSame([], $this->engine->searches, 'Meilisearch is not asked.');

        $engine->assertSearched(fn (Builder $search) => $search->query === 'shoes' && $search->index->entityType === 'searchable_product');
    }

    public function test_an_engine_is_handed_conditions_already_checked_and_cast(): void
    {
        $engine = $this->useCustomEngine();
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);
        $this->attribute('date', 'released', ['filterable' => true]);

        Search::for(SearchableProduct::class)
            ->filter(['price' => ['gte' => '10'], 'released' => ['lt' => '2026-01-01']])
            ->whereIn('parent', ['1', '2'])
            ->sort('-price')
            ->paginate();

        $search = $engine->searches[0];
        [$price, $released] = $search->filters[0]->members;
        [$parent] = $search->filters[1]->members;

        $this->assertSame(['attributes.price', Comparison::GreaterOrEqual, 10], [$price->field->path, $price->comparison, $price->value]);
        $this->assertSame([Comparison::Less, strtotime('2026-01-01')], [$released->comparison, $released->value]);
        $this->assertSame(['parent_id', Comparison::In, [1, 2]], [$parent->field->path, $parent->comparison, $parent->value]);
        $this->assertSame(['attributes.price', 'desc'], [$search->orders[0][0]->path, $search->orders[0][1]]);
    }

    public function test_a_request_is_refused_before_it_reaches_any_engine(): void
    {
        $engine = $this->useCustomEngine();
        $this->attribute('text', 'sku', ['searchable' => true]);

        $attempts = [
            fn ($search) => $search->filter(['nope = 1 OR id > 0 OR nope' => 1]),
            fn ($search) => $search->filter(['sku' => 'x']),
            fn ($search) => $search->where('parent', 'not a number'),
            fn ($search) => $search->sort('sku'),
            fn ($search) => $search->facets('sku'),
            fn ($search) => $search->searchOn('parent'),
            fn ($search) => $search->distinct('sku'),
            fn ($search) => $search->highlight('nope'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt(Search::for(SearchableProduct::class))->paginate();
                $this->fail('The request should have been refused.');
            } catch (InvalidSearchQueryException) {
                // Refused, as it should be.
            }
        }

        $engine->assertNothingSearched();
    }

    public function test_facets_of_any_engine_get_labels_and_one_shape(): void
    {
        $engine = $this->useCustomEngine();
        $color = $this->attribute('select', 'color', ['filterable' => true]);
        $this->option($color, 'red', ['en' => 'Red']);
        $this->attribute('number', 'price', ['filterable' => true]);

        $engine->respond('searchable_product', [], facets: ['color' => ['red' => 2], 'price' => ['min' => 5, 'max' => 9]]);

        $this->assertSame([
            'color' => ['type' => 'terms', 'values' => [['value' => 'red', 'label' => 'Red', 'count' => 2]]],
            'price' => ['type' => 'range', 'min' => 5, 'max' => 9],
        ], Search::for(SearchableProduct::class)->facets(['color', 'price'])->paginate()->facets());
    }

    public function test_an_engine_that_cannot_federate_has_its_searches_merged_by_weighted_score(): void
    {
        $engine = $this->useCustomEngine();
        $this->attribute('select', 'color', ['filterable' => true]);

        $shoes = $this->product('Shoes');
        $boots = $this->product('Boots');
        $footwear = SearchableCategory::create(['name' => 'Footwear']);

        $engine->respond('searchable_product', [
            ['id' => $shoes->id, '_score' => 0.9],
            ['id' => $boots->id, '_score' => 0.4],
        ], total: 2, facets: ['color' => ['red' => 1]]);
        $engine->respond('searchable_category', [['id' => $footwear->id, '_score' => 0.5]], total: 1);

        $federated = fn () => Search::federated([
            Search::for(SearchableProduct::class)->query('x')->facets('color'),
            Search::for(SearchableCategory::class)->query('x')->weight(2),
        ]);

        // 0.5 × 2 outranks 0.9; 0.4 comes last.
        $results = $federated()->paginate(2);

        $this->assertSame(['Footwear', 'Shoes'], $results->getCollection()->pluck('name')->all());
        $this->assertSame(['searchable_category', 'searchable_product'], $results->hits()->pluck('entityType')->all());
        $this->assertSame(3, $results->total());
        $this->assertSame('red', $results->facets()['searchable_product']['color']['values'][0]['value']);

        $this->assertSame(['Boots'], $federated()->paginate(2, 2)->getCollection()->pluck('name')->all());
        $this->assertSame(['Footwear', 'Shoes', 'Boots'], $federated()->get()->pluck('name')->all());
    }

    public function test_each_federated_search_loads_its_own_hits(): void
    {
        $engine = $this->useCustomEngine();
        $child = $this->product('Child', $this->product('Parent')->id);
        $engine->respond('searchable_product', [['id' => $child->id, '_score' => 0.5]]);

        // Two searches over one entity type: each loads its hits its own way.
        $results = Search::federated([
            Search::for(SearchableProduct::class)->query('a')->with('parent'),
            Search::for(SearchableProduct::class)->query('b')->loadUsing(fn ($query) => $query->whereKey(0)),
        ])->paginate();

        [$first, $second] = $results->hits()->all();

        $this->assertTrue($first->model->relationLoaded('parent'));
        $this->assertNull($second->model);
    }

    public function test_federating_nothing_finds_nothing(): void
    {
        $this->assertCount(0, Search::federated([])->paginate());
        $this->assertSame([], $this->engine->searches);
    }

    public function test_comparing_with_null_asks_whether_a_value_is_held(): void
    {
        $engine = $this->useCustomEngine();

        Search::for(SearchableProduct::class)->where('parent', null)->where('parent', '!=', null)->partition([])->paginate();

        $search = $engine->searches[0];

        $this->assertSame(Comparison::Missing, $search->filters[0]->members[0]->comparison);
        $this->assertSame(Comparison::Exists, $search->filters[1]->members[0]->comparison);
        $this->assertNull($search->partition);
    }

    public function test_an_engine_that_cannot_search_facets_has_their_values_matched(): void
    {
        $engine = $this->useCustomEngine();
        $color = $this->attribute('select', 'color', ['filterable' => true]);
        $this->option($color, 'red', ['en' => 'Red']);

        $engine->respond('searchable_product', [], facets: ['color' => ['red' => 2, 'blue' => 1, 'dark-red' => 4]]);

        $search = Search::for(SearchableProduct::class);

        $this->assertSame(['red', 'dark-red'], array_column($search->facetValues('color', 'RED'), 'value'));
        $this->assertSame('Red', $search->facetValues('color', 'red')[0]['label']);
        $this->assertCount(3, $search->facetValues('color'));
    }

    public function test_an_engine_without_index_settings_is_left_alone(): void
    {
        $this->useCustomEngine();
        $this->product();
        config(['scout.queue' => true]);
        Queue::fake();

        $this->artisan('eav:sync-index-settings', ['type' => 'searchable_product'])
            ->expectsOutputToContain('Nothing to sync for [searchable_product]')
            ->assertSuccessful();

        $this->artisan('eav:reindex', ['type' => 'searchable_product'])->assertSuccessful();

        $this->assertSame([], $this->engine->settings);
        Queue::assertPushed(MakeSearchable::class, 1);
    }

    public function test_index_settings_are_skipped_when_the_engine_is_not_installed(): void
    {
        // Scout indexing entities on its own, without the client of the engine named as the driver.
        Search::extend('meilisearch', fn () => throw SearchNotAvailableException::notInstalled());

        $this->app->call([new SyncIndexSettings('searchable_product'), 'handle']);

        $this->assertSame([], $this->engine->settings);
    }

    public function test_fake_answers_searches_and_records_them(): void
    {
        $fake = Search::fake();
        $product = $this->product();

        $fake->assertNothingSearched();
        $fake->respond('searchable_product', [$product->id]);

        $this->assertTrue(Search::for(SearchableProduct::class)->query('widget')->locale('ru')->first()->is($product));
        $this->assertSame([], $this->engine->searches, 'Meilisearch is not asked.');

        $fake->assertSearched(fn (Builder $search) => $search->query === 'widget' && $search->locales() === ['ru']);
    }
}
