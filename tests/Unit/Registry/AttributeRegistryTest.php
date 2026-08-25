<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Tests\TestCase;

class AttributeRegistryTest extends TestCase
{
    private AttributeRegistry $registry;

    private Attribute $name;

    private Attribute $price;

    private Attribute $categoryCode;

    protected function setUp(): void
    {
        parent::setUp();

        $type = AttributeType::create(['code' => 'text']);

        $this->name = Attribute::create([
            'entity_type' => 'product',
            'attribute_type_id' => $type->id,
            'code' => 'name',
            'sort' => 0,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->price = Attribute::create([
            'entity_type' => 'product',
            'attribute_type_id' => $type->id,
            'code' => 'price',
            'sort' => 1,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->categoryCode = Attribute::create([
            'entity_type' => 'category',
            'attribute_type_id' => $type->id,
            'code' => 'code',
            'sort' => 0,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->registry = app(AttributeRegistry::class);
        $this->registry->forget();
    }

    /** Queries against the attributes table, ignoring the ones other registries warm themselves with. */
    private function queriesOnAttributes(): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], '"attributes"')
        ));
    }

    /** Start a fresh request: the container drops the instance, the sets it holds outlive it. */
    private function nextRequest(): void
    {
        app()->forgetScopedInstances();

        $this->registry = app(AttributeRegistry::class);

        DB::flushQueryLog();
        DB::disableQueryLog();
    }

    public function test_for_entity_type_returns_collection_keyed_by_id(): void
    {
        $products = $this->registry->forEntityType('product');

        $this->assertTrue($products->has($this->name->id));
        $this->assertTrue($products->has($this->price->id));
    }

    public function test_for_entity_type_excludes_attributes_of_other_entity_types(): void
    {
        $products = $this->registry->forEntityType('product');

        $this->assertFalse($products->has($this->categoryCode->id));
    }

    public function test_for_entity_type_caches_are_kept_separate_per_entity_type(): void
    {
        $products = $this->registry->forEntityType('product');
        $categories = $this->registry->forEntityType('category');

        $this->assertCount(2, $products);
        $this->assertCount(1, $categories);
    }

    public function test_for_entity_type_is_cached_after_first_call(): void
    {
        $first = $this->registry->forEntityType('product');
        $second = $this->registry->forEntityType('product');

        $this->assertSame($first, $second);
    }

    public function test_warming_one_entity_type_does_not_warm_another(): void
    {
        $this->registry->forEntityType('product');

        Attribute::create([
            'entity_type' => 'category',
            'attribute_type_id' => $this->categoryCode->attribute_type_id,
            'code' => 'seo_title',
            'sort' => 1,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        // Not yet cached for 'category', so the freshly created row is visible.
        $this->assertCount(2, $this->registry->forEntityType('category'));
    }

    public function test_creating_an_attribute_automatically_invalidates_the_cache(): void
    {
        $first = $this->registry->forEntityType('product');

        Attribute::create([
            'entity_type' => 'product',
            'attribute_type_id' => $this->name->attribute_type_id,
            'code' => 'weight',
            'sort' => 2,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $second = $this->registry->forEntityType('product');

        $this->assertNotSame($first, $second);
        $this->assertCount(3, $second);
    }

    public function test_has_returns_true_for_existing_id(): void
    {
        $this->assertTrue($this->registry->has($this->name->id, 'product'));
    }

    public function test_has_returns_false_for_missing_id(): void
    {
        $this->assertFalse($this->registry->has(9999, 'product'));
    }

    public function test_has_returns_false_when_id_belongs_to_another_entity_type(): void
    {
        $this->assertFalse($this->registry->has($this->categoryCode->id, 'product'));
    }

    public function test_get_returns_attribute_model(): void
    {
        $attribute = $this->registry->get($this->price->id, 'product');

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('price', $attribute->code);
    }

    public function test_get_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->registry->get(9999, 'product'));
    }

    public function test_get_resolves_an_attribute_added_after_the_set_was_loaded(): void
    {
        $this->registry->forEntityType('product');

        // Written straight to the table: the observer that clears the registry runs in the process
        // performing the write, which on a long-running server is not this one.
        $id = DB::table('attributes')->insertGetId([
            'entity_type' => 'product',
            'attribute_type_id' => $this->name->attribute_type_id,
            'code' => 'weight',
            'sort' => 2,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $attribute = $this->registry->get($id, 'product');

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('weight', $attribute->code);
    }

    public function test_get_keeps_a_resolved_miss_without_querying_again(): void
    {
        $this->registry->forEntityType('product');

        $id = DB::table('attributes')->insertGetId([
            'entity_type' => 'product',
            'attribute_type_id' => $this->name->attribute_type_id,
            'code' => 'weight',
            'sort' => 2,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->registry->get($id, 'product');

        DB::enableQueryLog();
        $this->registry->get($id, 'product');

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_get_does_not_query_again_for_an_id_that_does_not_exist(): void
    {
        $this->assertNull($this->registry->get(9999, 'product'));

        DB::enableQueryLog();

        $this->assertNull($this->registry->get(9999, 'product'));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_get_does_not_leak_an_attribute_of_another_entity_type(): void
    {
        $this->assertNull($this->registry->get($this->categoryCode->id, 'product'));
    }

    public function test_an_edit_made_elsewhere_is_picked_up_on_the_next_request(): void
    {
        $this->assertSame('price', $this->registry->get($this->price->id, 'product')->code);

        // Written straight to the table: the observer clearing the registry runs in the process
        // performing the write, which on a long-running server is not this one.
        DB::table('attributes')->where('id', $this->price->id)->update(['code' => 'cost', 'updated_at' => now()->addMinute()]);

        $this->nextRequest();

        $this->assertSame('cost', $this->registry->get($this->price->id, 'product')->code);
    }

    public function test_a_deletion_made_elsewhere_is_picked_up_on_the_next_request(): void
    {
        $this->assertCount(2, $this->registry->forEntityType('product'));

        DB::table('attributes')->where('id', $this->price->id)->delete();

        $this->nextRequest();

        $this->assertCount(1, $this->registry->forEntityType('product'));
    }

    public function test_a_delete_balanced_out_by_an_insert_is_still_picked_up(): void
    {
        $this->registry->forEntityType('product');

        DB::table('attributes')->where('id', $this->price->id)->delete();
        DB::table('attributes')->insertGetId([
            'entity_type' => 'product',
            'attribute_type_id' => $this->name->attribute_type_id,
            'code' => 'weight',
            'sort' => 2,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $this->nextRequest();

        $this->assertSame(
            ['name', 'weight'],
            $this->registry->forEntityType('product')->pluck('code')->sort()->values()->all()
        );
    }

    public function test_an_untouched_set_is_served_from_memory_without_reading_the_rows(): void
    {
        $this->registry->forEntityType('product');

        $this->nextRequest();

        DB::enableQueryLog();
        $this->registry->forEntityType('product');

        // One aggregate to confirm nothing moved — and no second query for the rows themselves.
        $this->assertSame(1, $this->queriesOnAttributes());
    }

    public function test_the_set_is_checked_once_per_request_however_often_it_is_read(): void
    {
        $this->registry->forEntityType('product');

        $this->nextRequest();

        DB::enableQueryLog();

        $this->registry->forEntityType('product');
        $this->registry->forEntityType('product');
        $this->registry->forEntityType('product');

        $this->assertSame(1, $this->queriesOnAttributes());
    }

    public function test_a_set_loaded_in_this_request_is_not_checked_again(): void
    {
        DB::enableQueryLog();

        $this->registry->forEntityType('product');
        $this->registry->forEntityType('product');

        // The stamp taken when loading, and the rows — nothing on top of that.
        $this->assertSame(2, $this->queriesOnAttributes());
    }

    public function test_forget_clears_cache_for_all_entity_types(): void
    {
        $this->registry->forEntityType('product');
        $this->registry->forEntityType('category');

        $this->registry->forget();

        $this->assertCount(2, $this->registry->forEntityType('product'));
        $this->assertCount(1, $this->registry->forEntityType('category'));
    }
}
