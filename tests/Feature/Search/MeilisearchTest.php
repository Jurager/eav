<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Contracts\Console\Kernel;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Jobs\ReindexAttribute;
use Jurager\Eav\Jobs\ReindexEntities;
use Jurager\Eav\Search\Results;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Meilisearch\Client;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs against a real Meilisearch server: set MEILISEARCH_HOST (and MEILISEARCH_KEY) to enable.
 */
#[Group('meilisearch')]
class MeilisearchTest extends SearchTestCase
{
    private const array INDEXES = ['eav_test_products', 'eav_test_categories'];

    private Client $client;

    /**
     * Search through Scout's real Meilisearch engine instead of the recording the other search tests use.
     */
    protected function useRecordingEngine(): void {}

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.driver', 'meilisearch');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.meilisearch.host', env('MEILISEARCH_HOST'));
        $app['config']->set('scout.meilisearch.key', env('MEILISEARCH_KEY'));
    }

    protected function setUp(): void
    {
        if (! env('MEILISEARCH_HOST')) {
            $this->markTestSkipped('Set MEILISEARCH_HOST to run the Meilisearch tests.');
        }

        parent::setUp();

        $this->client = $this->app->make(Client::class);

        $this->dropIndexes();
        $this->seedCatalog();
    }

    protected function tearDown(): void
    {
        // Skipped before the application was set up: nothing to tear down.
        if (! isset($this->client)) {
            return;
        }

        $this->dropIndexes();

        parent::tearDown();
    }

    private function dropIndexes(): void
    {
        foreach (self::INDEXES as $index) {
            $this->client->deleteIndex($index);
        }

        $this->settle();
    }

    /**
     * Wait until the engine has processed everything sent to it.
     */
    private function settle(): void
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if ($this->client->getTasks((new \Meilisearch\Contracts\TasksQuery)->setStatuses(['enqueued', 'processing']))->getResults() === []) {
                return;
            }

            usleep(50_000);
        }

        $this->fail('Meilisearch did not settle in time.');
    }

    private function seedCatalog(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        $this->attribute('text', 'sku', ['searchable' => true, 'filterable' => true]);
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);
        $this->attribute('date', 'released', ['filterable' => true]);
        $color = $this->attribute('select', 'color', ['filterable' => true, 'searchable' => true]);
        $red = $this->option($color, 'red', ['en' => 'Red', 'ru' => 'Красный']);
        $blue = $this->option($color, 'blue', ['en' => 'Blue', 'ru' => 'Синий']);

        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('scout:sync-index-settings')->assertSuccessful();

        $rows = [
            ['Shoes', 'Red running shoes', 'Красные кроссовки', 'RS-100', 100, '2026-01-10', $red],
            ['Jacket', 'Blue winter jacket', 'Синяя зимняя куртка', 'BJ-200', 250, '2026-03-01', $blue],
            ['Boots', 'Red leather boots', 'Красные кожаные ботинки', 'RB-300', 400, '2026-06-01', $red],
            ['Cap', 'Blue cap', 'Синяя кепка', 'BC-400', 30, '2025-12-01', $blue],
        ];

        foreach ($rows as [$name, $en, $ru, $sku, $price, $released, $option]) {
            $this->product($name)->eav()
                ->set('title', $en, $this->en->id)
                ->set('title', $ru, $this->ru->id)
                ->set('sku', $sku)
                ->set('price', $price)
                ->set('released', $released)
                ->set('color', $option->id)
                ->save();
        }

        SearchableCategory::create(['name' => 'Shoes and boots']);
        SearchableCategory::create(['name' => 'Outerwear']);

        $this->settle();
    }

    private function names(iterable $models): array
    {
        return collect($models instanceof Results ? $models->items() : $models)->pluck('name')->all();
    }

    public function test_a_misspelt_word_matches_in_the_searched_locale_only(): void
    {
        $this->assertSame(['Shoes'], $this->names(SearchableProduct::search('кросовки')->locale('ru')->get()));
        $this->assertSame([], $this->names(SearchableProduct::search('кросовки')->locale('en')->get()));
        $this->assertSame(['Jacket'], $this->names(SearchableProduct::search('jaket')->get()));
    }

    public function test_declared_fields_and_option_labels_are_searched(): void
    {
        $this->assertSame(['Cap'], $this->names(SearchableProduct::search('cap')->searchOn('name')->get()));
        $this->assertEqualsCanonicalizing(
            ['Jacket', 'Cap'],
            $this->names(SearchableProduct::search('синий')->locale('ru')->searchOn('labels.color')->get()),
        );
    }

    public function test_filters_sorting_and_exact_totals(): void
    {
        $results = SearchableProduct::search()
            ->filter(['color' => 'red', 'price' => ['gte' => 50]])
            ->sort('-price')
            ->paginate(1);

        $this->assertSame(['Boots'], $this->names($results));
        $this->assertSame(2, $results->total());
        $this->assertSame(2, $results->lastPage());
        $this->assertSame(3, SearchableProduct::search()->where('price', '>=', 100)->count());
    }

    public function test_dates_are_filtered_by_date_strings(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Jacket', 'Boots'],
            $this->names(SearchableProduct::search()->filter(['released' => ['gte' => '2026-02-01']])->get()),
        );
    }

    public function test_facets_carry_labels_ranges_and_disjunctive_counts(): void
    {
        $facets = SearchableProduct::search()
            ->where('color', 'red')
            ->facets(['color', 'price'])
            ->locale('ru')
            ->paginate()
            ->facets();

        $this->assertEqualsCanonicalizing([
            ['value' => 'red', 'label' => 'Красный', 'count' => 2],
            ['value' => 'blue', 'label' => 'Синий', 'count' => 2],
        ], $facets['color']['values']);
        $this->assertEquals(['type' => 'range', 'min' => 100, 'max' => 400], $facets['price']);
    }

    public function test_facet_values_can_be_searched(): void
    {
        $this->assertSame(
            [['value' => 'blue', 'label' => 'Blue', 'count' => 2]],
            SearchableProduct::search()->facetValues('color', 'bl'),
        );
    }

    public function test_highlighting_marks_the_matched_words(): void
    {
        $boots = SearchableProduct::search('leather')->highlight('title')->paginate()->first();

        $this->assertSame('Red <em>leather</em> boots', $boots->highlighted('title'));
        $this->assertSame('Красные кожаные ботинки', $boots->highlighted('title', 'ru'));
        $this->assertGreaterThan(0, $boots->scoutMetadata()['_rankingScore']);
    }

    public function test_partition_leads_with_matches_across_pages(): void
    {
        $search = fn () => SearchableProduct::search()->sort('price')->partition(['color' => 'blue']);

        $this->assertSame(['Cap', 'Jacket', 'Shoes'], $this->names($search()->paginate(3, 'page', 1)));
        $this->assertSame(['Boots'], $this->names($search()->paginate(3, 'page', 2)));
        $this->assertSame(4, $search()->paginate(3, 'page', 1)->total());
    }

    public function test_federated_search_ranks_entity_types_together(): void
    {
        $results = Search::federated([
            SearchableProduct::search('boots')->facets('color'),
            SearchableCategory::search('boots')->weight(2),
        ])->paginate(10);

        $this->assertSame(['Shoes and boots', 'Boots'], $this->names($results));
        $this->assertSame(['searchable_category', 'searchable_product'], $results->getCollection()->map->getEntityType()->all());
        $this->assertSame('red', $results->facets()['searchable_product']['color']['values'][0]['value']);
    }

    public function test_a_request_the_engine_rejects_is_a_422(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        SearchableProduct::search()->options(['matchingStrategy' => 'nope'])->paginate();
    }

    public function test_a_changed_value_is_reindexed(): void
    {
        $cap = SearchableProduct::query()->firstWhere('name', 'Cap');
        $cap->eav()->set('price', 999)->save();

        $this->settle();

        $this->assertSame(['Cap'], $this->names(SearchableProduct::search()->where('price', '>', 500)->get()));
    }

    public function test_a_removed_locale_leaves_the_documents_and_the_settings(): void
    {
        $shoes = SearchableProduct::query()->firstWhere('name', 'Shoes');
        Queue::fake();

        $this->ru->delete();

        // Run what the removal queued.
        foreach ([SyncIndexSettings::class, ReindexEntities::class] as $job) {
            Queue::pushed($job)->each(fn ($job) => $this->app->call([$job, 'handle']));
        }

        $this->settle();

        $index = $this->client->index('eav_test_products');

        $document = $index->getDocument($shoes->id);

        $this->assertSame(['en' => 'Red running shoes'], $document['attributes']['title']);
        $this->assertSame(['color' => ['en' => 'Red']], $document['labels']);
        $this->assertNotContains('attributes.title.ru', $index->getSearchableAttributes());
        $this->assertSame([], $this->names(SearchableProduct::search('кроссовки')->get()));
    }

    public function test_the_remaining_conditions_run_against_the_server(): void
    {
        $search = fn () => SearchableProduct::search();

        $this->assertEqualsCanonicalizing(['Shoes', 'Boots'], $this->names($search()->whereNotIn('color', ['blue'])->get()));
        $this->assertEqualsCanonicalizing(['Shoes', 'Boots'], $this->names($search()->where('color', '!=', 'blue')->get()));
        $this->assertSame(4, $search()->whereNull('parent')->count());
        $this->assertSame(0, $search()->whereNotNull('parent')->count());
        $this->assertSame(0, $search()->whereIn('sku', [])->count());
        $this->assertEqualsCanonicalizing(['Jacket', 'Boots', 'Cap'], $this->names($search()->whereNotBetween('price', [50, 200])->get()));
        $this->assertSame(['Cap'], $this->names($search()->filter(['or' => [['price' => ['lt' => 50]], ['sku' => 'nope']]])->get()));
    }

    public function test_ranking_options_run_against_the_server(): void
    {
        $this->assertSame([], $this->names(SearchableProduct::search('red jacket')->options(['matchingStrategy' => 'all'])->get()));
        $this->assertNotSame([], $this->names(SearchableProduct::search('red jacket')->get()));
        $this->assertSame([], $this->names(SearchableProduct::search('shoes')->options(['rankingScoreThreshold' => 1.0])->searchOn('sku')->get()));
        $this->assertCount(2, SearchableProduct::search()->distinct('color')->get());
    }

    public function test_like_matches_a_substring_when_the_server_allows_it(): void
    {
        // CONTAINS is an experimental feature the server has to be told to allow.
        Http::withToken((string) env('MEILISEARCH_KEY'))
            ->patch(env('MEILISEARCH_HOST').'/experimental-features', ['containsFilter' => true])
            ->throw();

        config(['eav.search.meilisearch.contains' => true]);

        $this->assertSame(['Jacket'], $this->names(SearchableProduct::search()->filter(['sku' => ['like' => '%J-2%']])->get()));
        $this->assertEqualsCanonicalizing(
            ['Jacket', 'Cap'],
            $this->names(SearchableProduct::search()->filter(['sku' => ['like' => ['BJ', 'BC']]])->get()),
        );
    }

    public function test_a_deleted_entity_leaves_the_index(): void
    {
        SearchableProduct::query()->firstWhere('name', 'Cap')->delete();
        $this->settle();

        $this->assertSame(3, SearchableProduct::search()->count());
    }

    public function test_a_document_whose_row_is_gone_is_pruned_by_the_search_that_finds_it(): void
    {
        $cap = SearchableProduct::query()->firstWhere('name', 'Cap');
        DB::table('products')->where('id', $cap->id)->delete();

        $results = SearchableProduct::search()->where('price', '<', 50)->paginate();

        $this->assertSame(1, $results->total());
        $this->assertCount(0, $results->items());

        $this->settle();

        $this->assertSame(0, SearchableProduct::search()->where('price', '<', 50)->count());
    }

    public function test_a_variant_follows_the_values_it_inherits(): void
    {
        $this->attribute('text', 'material', ['searchable' => true, 'filterable' => true, 'inherit_from_parent' => true]);
        $this->app->call([new SyncIndexSettings('searchable_product'), 'handle']);

        $parent = SearchableProduct::query()->firstWhere('name', 'Boots');
        $this->product('Boots 42', $parent->id);
        $parent->eav()->set('material', 'leather')->save();
        $this->settle();

        $this->assertEqualsCanonicalizing(
            ['Boots', 'Boots 42'],
            $this->names(SearchableProduct::search()->where('material', 'leather')->get()),
        );

        $parent->eav()->set('material', 'suede')->save();
        $this->settle();

        $this->assertSame([], $this->names(SearchableProduct::search()->where('material', 'leather')->get()));
        $this->assertCount(2, SearchableProduct::search()->where('material', 'suede')->get());
    }

    public function test_flagging_an_attribute_makes_it_searchable_once_the_queued_work_ran(): void
    {
        $price = Attribute::query()->firstWhere('code', 'price');
        $released = Attribute::query()->firstWhere('code', 'released');
        Queue::fake();

        $released->update(['sortable' => true]);
        $price->update(['filterable' => false]);

        foreach ([SyncIndexSettings::class, ReindexAttribute::class] as $job) {
            Queue::pushed($job)->each(fn ($job) => $this->app->call([$job, 'handle']));
        }

        $this->settle();

        $this->assertSame(['Cap', 'Shoes', 'Jacket', 'Boots'], $this->names(SearchableProduct::search()->sort('released')->get()));

        $this->expectExceptionMessage('field [price] is not filterable');

        SearchableProduct::search()->where('price', '>', 1);
    }

    public function test_a_boost_ranks_matching_hits_first_and_keeps_the_total_exact(): void
    {
        $search = fn () => SearchableProduct::search('red');

        $expensive = $search()->boost(['price' => ['gt' => 300]], 5)->paginate();
        $cheap = $search()->boost(['price' => ['lt' => 300]], 5)->paginate();

        $this->assertSame(['Boots', 'Shoes'], $this->names($expensive));
        $this->assertSame(['Shoes', 'Boots'], $this->names($cheap));
        $this->assertSame(2, $expensive->total());
        $this->assertSame(['Shoes'], $this->names($search()->boost(['price' => ['gt' => 300]], 5)->paginate(1, 'page', 2)));
    }

    public function test_a_boosted_search_federates_with_another_entity_type(): void
    {
        $federated = fn (float $boost) => Search::federated([
            SearchableProduct::search('boots')->boost(['color' => 'red'], $boost),
            SearchableCategory::search('boots')->weight(1.5),
        ])->paginate();

        // The red boots match the products search and its boosted part, yet appear once.
        $this->assertSame(['Shoes and boots', 'Boots'], $this->names($federated(1.0)));
        $this->assertSame(['Boots', 'Shoes and boots'], $this->names($federated(4.0)));
        $this->assertCount(2, $federated(4.0)->items());
    }

    public function test_a_filtered_facet_of_a_federated_search_keeps_its_other_values(): void
    {
        $facets = Search::federated([
            SearchableProduct::search()->where('color', 'red')->facets('color'),
            SearchableCategory::search(),
        ])->paginate()->facets();

        $this->assertEqualsCanonicalizing(
            [['value' => 'red', 'label' => 'Red', 'count' => 2], ['value' => 'blue', 'label' => 'Blue', 'count' => 2]],
            $facets['searchable_product']['color']['values'],
        );
    }
}
