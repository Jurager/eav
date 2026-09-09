<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Registry\AttributeGroupRegistry;
use Jurager\Eav\Tests\TestCase;

class AttributeGroupRegistryTest extends TestCase
{
    private AttributeGroupRegistry $registry;

    private AttributeGroup $general;

    private AttributeGroup $dimensions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->general = AttributeGroup::create(['code' => 'general', 'sort' => 0]);
        $this->dimensions = AttributeGroup::create(['code' => 'dimensions', 'sort' => 1]);

        $this->registry = app(AttributeGroupRegistry::class);
        $this->registry->forget();
    }

    public function test_all_returns_collection_keyed_by_id(): void
    {
        $all = $this->registry->all();

        $this->assertTrue($all->has($this->general->id));
        $this->assertTrue($all->has($this->dimensions->id));
    }

    public function test_all_is_cached_after_first_call(): void
    {
        $first = $this->registry->all();
        $second = $this->registry->all();

        $this->assertSame($first, $second);
    }

    public function test_creating_a_group_automatically_invalidates_the_cache(): void
    {
        $first = $this->registry->all();

        AttributeGroup::create(['code' => 'seo', 'sort' => 2]);

        $second = $this->registry->all();

        $this->assertNotSame($first, $second);
        $this->assertCount(3, $second);
    }

    public function test_has_returns_true_for_existing_id(): void
    {
        $this->assertTrue($this->registry->has($this->general->id));
    }

    public function test_has_returns_false_for_missing_id(): void
    {
        $this->assertFalse($this->registry->has(9999));
    }

    public function test_get_returns_attribute_group_model(): void
    {
        $group = $this->registry->get($this->dimensions->id);

        $this->assertInstanceOf(AttributeGroup::class, $group);
        $this->assertSame('dimensions', $group->code);
    }

    public function test_get_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->registry->get(9999));
    }

    public function test_forget_clears_cache(): void
    {
        $this->registry->all();

        $this->registry->forget();

        AttributeGroup::create(['code' => 'seo', 'sort' => 2]);

        $this->assertCount(3, $this->registry->all());
    }

    public function test_get_resolves_by_point_lookup_without_loading_the_whole_table(): void
    {
        DB::enableQueryLog();

        $group = $this->registry->get($this->dimensions->id);

        $this->assertSame('dimensions', $group->code);

        $rowQueries = array_values(array_filter(
            DB::getQueryLog(),
            fn ($q) => str_contains($q['query'], 'from "attribute_groups"') && ! str_contains($q['query'], 'count(*)')
        ));

        $this->assertCount(1, $rowQueries);
        $this->assertStringContainsString('"id" = ?', $rowQueries[0]['query']);
    }

    public function test_get_picks_up_an_edit_made_elsewhere_on_the_next_request_without_ever_loading_the_whole_table(): void
    {
        $this->assertSame('dimensions', $this->registry->get($this->dimensions->id)->code);

        // Written straight to the table: the observer that clears the registry runs in the
        // process performing the write, which on a long-running server is not this one.
        DB::table('attribute_groups')->where('id', $this->dimensions->id)->update(['code' => 'renamed', 'updated_at' => now()->addMinute()]);

        app()->forgetScopedInstances();
        $registry = app(AttributeGroupRegistry::class);

        $this->assertSame('renamed', $registry->get($this->dimensions->id)->code);
    }

}
