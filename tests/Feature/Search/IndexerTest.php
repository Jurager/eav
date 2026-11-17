<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Support\Facades\Queue;
use Jurager\Eav\Search\Index\Indexer;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Laravel\Scout\Jobs\MakeSearchable;

class IndexerTest extends SearchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.queue' => true]);
    }

    public function test_reindex_command_syncs_settings_and_queues_every_entity(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);
        $first = $this->product('First');
        $second = $this->product('Second');
        Queue::fake();

        $this->artisan('eav:search:reindex', ['type' => 'searchable_product'])->assertSuccessful();

        $this->assertContains('attributes.price', $this->engine->settings['eav_test_products']['filterableAttributes']);
        $this->assertArrayNotHasKey('eav_test_categories', $this->engine->settings);
        Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job) => $job->models->modelKeys() === [$first->id, $second->id]);
    }

    public function test_reindex_command_respects_the_chunk_size(): void
    {
        $this->product('First');
        $this->product('Second');
        Queue::fake();

        $this->artisan('eav:search:reindex', ['type' => 'searchable_product', '--chunk' => 1])->assertSuccessful();

        Queue::assertPushed(MakeSearchable::class, 2);
    }

    public function test_reindex_command_covers_every_searchable_type_and_reports_others(): void
    {
        $this->artisan('eav:search:reindex')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['eav_test_products', 'eav_test_categories'], array_keys($this->engine->settings));

        $this->artisan('eav:search:reindex', ['type' => 'product'])
            ->expectsOutputToContain('[product] is not searchable')
            ->assertSuccessful();
    }

    public function test_since_queues_only_what_was_touched_after_the_moment(): void
    {
        $old = $this->product('Old');
        SearchableProduct::query()->whereKey($old->id)->update(['updated_at' => now()->subDay()]);
        $recent = $this->product('Recent');
        $latest = $this->product('Latest');
        Queue::fake();

        app(Indexer::class)->since('searchable_product', now()->subHour());

        Queue::assertPushed(MakeRangeSearchable::class, 1);
        Queue::assertPushed(MakeRangeSearchable::class, function (MakeRangeSearchable $job) use ($recent, $latest): bool {
            $range = (fn () => [$this->start, $this->end])->call($job);

            return $range == [$recent->id, $latest->id];
        });
    }

    public function test_an_entity_type_that_is_not_searchable_is_left_alone(): void
    {
        Queue::fake();

        app(Indexer::class)->ids('product', [1]);
        app(Indexer::class)->since('product', now()->subHour());
        app(Indexer::class)->all('product');

        Queue::assertNothingPushed();
    }
}
