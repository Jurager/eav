<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Index\Configurator;
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
        $search(TrashableProduct::search())->paginate();

        $queries = $this->engine->queries();

        return end($queries)['filter'] ?? null;
    }

    public function test_nothing_is_filtered_unless_scout_keeps_trashed_entities_in_the_index(): void
    {
        $this->assertNull($this->filter(fn ($search) => $search));

        app(Configurator::class)->configure('trashable_product');

        $this->assertNotContains('__soft_deleted', $this->engine->settings['eav_test_products']['filterableAttributes']);
    }

    public function test_scouts_soft_delete_marker_leaves_trashed_entities_out(): void
    {
        config(['scout.soft_delete' => true]);

        $this->assertSame('(__soft_deleted = 0)', $this->filter(fn ($search) => $search));
        $this->assertSame('(parent_id = 1) AND (__soft_deleted = 0)', $this->filter(fn ($search) => $search->where('parent', 1)));
        $this->assertNull($this->filter(fn ($search) => $search->withTrashed()));
        $this->assertSame('(__soft_deleted = 1)', $this->filter(fn ($search) => $search->onlyTrashed()));
    }

    public function test_scouts_soft_delete_marker_is_made_filterable(): void
    {
        config(['scout.soft_delete' => true]);

        app(Configurator::class)->configure('trashable_product');

        $this->assertContains('__soft_deleted', $this->engine->settings['eav_test_products']['filterableAttributes']);
    }

    public function test_trashed_entities_the_engine_returns_are_loaded(): void
    {
        config(['scout.soft_delete' => true]);

        $trashed = TrashableProduct::create(['name' => 'Trashed']);
        $trashed->delete();

        $this->engine->respond('eav_test_products', [['id' => $trashed->id]]);

        $this->assertSame(['Trashed'], TrashableProduct::search()->onlyTrashed()->get()->pluck('name')->all());
    }
}
