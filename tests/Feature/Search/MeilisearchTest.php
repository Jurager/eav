<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Contracts\Transport;
use Jurager\Eav\Search\Results;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\ScoutServiceProvider;
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

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScoutServiceProvider::class];
    }

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

        // Talk to the real engine instead of the fake the other search tests use.
        $this->app->forgetInstance(Transport::class);
        $this->client = $this->app->make(Client::class);

        $this->dropIndexes();
        $this->seedCatalog();
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->dropIndexes();
        }

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

        $this->artisan('eav:search:sync')->assertSuccessful();

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
        $this->assertSame(['Shoes'], $this->names(Search::for(SearchableProduct::class)->query('кросовки')->locale('ru')->get()));
        $this->assertSame([], $this->names(Search::for(SearchableProduct::class)->query('кросовки')->locale('en')->get()));
        $this->assertSame(['Jacket'], $this->names(Search::for(SearchableProduct::class)->query('jaket')->get()));
    }

    public function test_declared_fields_and_option_labels_are_searched(): void
    {
        $this->assertSame(['Cap'], $this->names(Search::for(SearchableProduct::class)->query('cap')->searchOn('name')->get()));
        $this->assertEqualsCanonicalizing(
            ['Jacket', 'Cap'],
            $this->names(Search::for(SearchableProduct::class)->query('синий')->locale('ru')->searchOn('color_label')->get()),
        );
    }

    public function test_filters_sorting_and_exact_totals(): void
    {
        $results = Search::for(SearchableProduct::class)
            ->filter(['color' => 'red', 'price' => ['gte' => 50]])
            ->sort('-price')
            ->paginate(1);

        $this->assertSame(['Boots'], $this->names($results));
        $this->assertSame(2, $results->total());
        $this->assertSame(2, $results->lastPage());
        $this->assertSame(3, Search::for(SearchableProduct::class)->where('price', '>=', 100)->count());
    }

    public function test_dates_are_filtered_by_date_strings(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Jacket', 'Boots'],
            $this->names(Search::for(SearchableProduct::class)->filter(['released' => ['gte' => '2026-02-01']])->get()),
        );
    }

    public function test_facets_carry_labels_ranges_and_disjunctive_counts(): void
    {
        $facets = Search::for(SearchableProduct::class)
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
            Search::for(SearchableProduct::class)->facetValues('color', 'bl'),
        );
    }

    public function test_highlighting_marks_the_matched_words(): void
    {
        $hit = Search::for(SearchableProduct::class)->query('leather')->highlight('title')->paginate()->hits()->first();

        $this->assertSame('Red <em>leather</em> boots', $hit->highlight('title'));
        $this->assertSame('Red leather boots', $hit->get('title'));
        $this->assertGreaterThan(0, $hit->score);
    }

    public function test_partition_leads_with_matches_across_pages(): void
    {
        $search = fn () => Search::for(SearchableProduct::class)->sort('price')->partition(['color' => 'blue']);

        $this->assertSame(['Cap', 'Jacket', 'Shoes'], $this->names($search()->paginate(3, 1)));
        $this->assertSame(['Boots'], $this->names($search()->paginate(3, 2)));
        $this->assertSame(4, $search()->paginate(3, 1)->total());
    }

    public function test_federated_search_ranks_entity_types_together(): void
    {
        $results = Search::federated([
            Search::for(SearchableProduct::class)->query('boots')->facets('color'),
            Search::for(SearchableCategory::class)->query('boots')->weight(2),
        ])->paginate(10);

        $this->assertSame(['Shoes and boots', 'Boots'], $this->names($results));
        $this->assertSame(['searchable_category', 'searchable_product'], $results->hits()->pluck('entityType')->all());
        $this->assertSame('red', $results->facets()['searchable_product']['color']['values'][0]['value']);
    }

    public function test_a_request_the_engine_rejects_is_a_422(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        Search::for(SearchableProduct::class)->options(['matchingStrategy' => 'nope'])->paginate();
    }

    public function test_a_changed_value_is_reindexed(): void
    {
        $cap = SearchableProduct::query()->firstWhere('name', 'Cap');
        $cap->eav()->set('price', 999)->save();

        $this->settle();

        $this->assertSame(['Cap'], $this->names(Search::for(SearchableProduct::class)->where('price', '>', 500)->get()));
    }
}
