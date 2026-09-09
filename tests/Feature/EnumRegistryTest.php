<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Registry\EnumRegistry;

class EnumRegistryTest extends FeatureTestCase
{
    private EnumRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, ['code' => 'color']);

        $this->registry = app(EnumRegistry::class);
        $this->registry->forget();
    }

    public function test_all_returns_empty_collection_when_no_enums(): void
    {
        $textType = $this->createAttributeType('text2');
        $attr = $this->createAttribute($textType, ['code' => 'size']);

        $result = $this->registry->all($attr->id);

        $this->assertTrue($result->isEmpty());
    }

    public function test_all_returns_enums_for_attribute(): void
    {
        $selectType = $this->createAttributeType('select');
        $attr = $this->createAttribute($selectType, ['code' => 'status']);

        $enum1 = $this->createEnum($attr, 'active');
        $enum2 = $this->createEnum($attr, 'inactive');

        $result = $this->registry->all($attr->id);

        $this->assertTrue($result->contains('id', $enum1->id));
        $this->assertTrue($result->contains('id', $enum2->id));
    }

    public function test_all_is_cached_after_first_call(): void
    {
        $selectType = $this->createAttributeType('select2');
        $attr = $this->createAttribute($selectType, ['code' => 'type']);

        $this->createEnum($attr, 'typeA');
        $this->createEnum($attr, 'typeB');

        $first = $this->registry->all($attr->id);
        $second = $this->registry->all($attr->id);

        $this->assertSame($first, $second);
    }

    public function test_forget_attribute_clears_its_cache(): void
    {
        $selectType = $this->createAttributeType('select3');
        $attr = $this->createAttribute($selectType, ['code' => 'flag']);

        $this->createEnum($attr, 'yes');
        $this->registry->all($attr->id);

        $this->registry->forget($attr->id);

        $enum2 = $this->createEnum($attr, 'no');

        $result = $this->registry->all($attr->id);

        $this->assertTrue($result->contains('id', $enum2->id));
    }

    public function test_forget_null_clears_all_caches(): void
    {
        $selectType = $this->createAttributeType('select4');
        $attr1 = $this->createAttribute($selectType, ['code' => 'x']);
        $attr2 = $this->createAttribute($selectType, ['code' => 'y']);

        $this->createEnum($attr1, 'a');
        $this->createEnum($attr2, 'b');

        $this->registry->all($attr1->id);
        $this->registry->all($attr2->id);

        $this->registry->forget();

        $e3 = $this->createEnum($attr1, 'c');
        $e4 = $this->createEnum($attr2, 'd');

        $r1 = $this->registry->all($attr1->id);
        $r2 = $this->registry->all($attr2->id);

        $this->assertTrue($r1->contains('id', $e3->id));
        $this->assertTrue($r2->contains('id', $e4->id));
    }

    // -----------------------------------------------------------------------
    // Cross-request caching (Octane: the registry is rebuilt, the enums aren't)
    // -----------------------------------------------------------------------

    public function test_enums_survive_a_fresh_registry_instance_with_only_a_stamp_check(): void
    {
        $selectType = $this->createAttributeType('select5');
        $attr = $this->createAttribute($selectType, ['code' => 'material']);
        $this->createEnum($attr, 'wood');

        $this->registry->all($attr->id);

        // Simulate the next Octane request: the container drops the scoped instance,
        // but the static state a fresh instance reads from must still be there.
        app()->forgetScopedInstances();
        $registry = app(EnumRegistry::class);

        DB::enableQueryLog();
        $all = $registry->all($attr->id);

        $this->assertCount(1, $all);
        // Just the staleness stamp — not the rows themselves.
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attribute_enums')));
    }

    public function test_an_edit_made_elsewhere_is_picked_up_on_the_next_request(): void
    {
        $selectType = $this->createAttributeType('select6');
        $attr = $this->createAttribute($selectType, ['code' => 'finish']);
        $enum = $this->createEnum($attr, 'matte');

        $this->assertSame('matte', $this->registry->find($attr->id, $enum->id)->code);

        // Written straight to the table: the observer that clears the registry runs in the
        // process performing the write, which on a long-running server is not this one.
        DB::table('attribute_enums')->where('id', $enum->id)->update(['code' => 'glossy', 'updated_at' => now()->addMinute()]);

        app()->forgetScopedInstances();
        $registry = app(EnumRegistry::class);

        $this->assertSame('glossy', $registry->find($attr->id, $enum->id)->code);
    }
}
