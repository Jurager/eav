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

    public function test_every_entity_of_a_type_is_queued_for_reindexing(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');
        Queue::fake();

        app(Indexer::class)->all('searchable_product');

        Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job) => $job->models->modelKeys() === [$first->id, $second->id]);
    }

    public function test_scouts_import_command_reindexes_an_entity_type(): void
    {
        $this->product('First');
        $this->product('Second');
        Queue::fake();

        $this->artisan('scout:import', ['model' => SearchableProduct::class, '--chunk' => 1])->assertSuccessful();

        Queue::assertPushed(MakeSearchable::class, 2);
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
