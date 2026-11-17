<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Index\Settings;
use Jurager\Eav\Tests\Fixtures\TrashableProduct;

class SoftDeletesTest extends SearchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['trashable_product' => TrashableProduct::class]);
        Schema::table('products', fn (Blueprint $table) => $table->softDeletes());
    }

    protected function tearDown(): void
    {
        // The morph map is static: leave no trace of the fixture for the tests that follow.
        Relation::morphMap([], false);

        parent::tearDown();
    }

    private function filter(callable $search): ?string
    {
        $search(Search::for(TrashableProduct::class))->paginate();

        $queries = $this->engine->queries();

        return end($queries)['filter'] ?? null;
    }

    public function test_trashed_entities_are_not_searched_unless_scout_keeps_them(): void
    {
        $this->assertNull($this->filter(fn ($search) => $search));
        $this->assertNotContains('__soft_deleted', app(Settings::class)->for(Search::index(TrashableProduct::class))['filterableAttributes']);
    }

    public function test_trashed_entities_kept_in_the_index_are_left_out_by_default(): void
    {
        config(['scout.soft_delete' => true]);

        $this->assertSame('(__soft_deleted = 0)', $this->filter(fn ($search) => $search));
        $this->assertSame('(parent_id = 1) AND (__soft_deleted = 0)', $this->filter(fn ($search) => $search->where('parent', 1)));
        $this->assertContains('__soft_deleted', app(Settings::class)->for(Search::index(TrashableProduct::class))['filterableAttributes']);
    }

    public function test_trashed_entities_can_be_included_or_searched_alone(): void
    {
        config(['scout.soft_delete' => true]);

        $kept = TrashableProduct::create(['name' => 'Kept']);
        $trashed = TrashableProduct::create(['name' => 'Trashed']);
        $trashed->delete();

        $this->engine->respond('eav_test_products', [['id' => $kept->id], ['id' => $trashed->id]]);

        $this->assertNull($this->filter(fn ($search) => $search->withTrashed()));
        $this->assertSame('(__soft_deleted = 1)', $this->filter(fn ($search) => $search->onlyTrashed()));

        $this->assertSame(['Kept'], Search::for(TrashableProduct::class)->get()->pluck('name')->all());
        $this->assertSame(['Kept', 'Trashed'], Search::for(TrashableProduct::class)->withTrashed()->get()->pluck('name')->all());
        $this->assertSame(['Trashed'], Search::for(TrashableProduct::class)->onlyTrashed()->get()->pluck('name')->all());
    }
}
