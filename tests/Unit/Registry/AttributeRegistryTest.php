<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Observers\AttributeObserver;
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

        $this->name = $this->createAttribute($type, 'name', 0, ['product']);
        $this->price = $this->createAttribute($type, 'price', 1, ['product']);
        $this->categoryCode = $this->createAttribute($type, 'code', 0, ['category']);

        $this->registry = app(AttributeRegistry::class);
        $this->registry->forget();
    }

    private function createAttribute(AttributeType $type, string $code, int $sort, array $entityTypes): Attribute
    {
        $attribute = Attribute::create([
            'attribute_type_id' => $type->id,
            'code' => $code,
            'sort' => $sort,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        $attribute->entityTypes()->createMany(
            array_map(fn (string $entityType) => ['entity_type' => $entityType], $entityTypes)
        );
        $attribute->unsetRelation('entityTypes');

        // The `created` hook fired above before the pivot rows existed to see — a `touch()` isn't
        // reliable here (same-second timestamps leave nothing dirty to save), so invalidate directly.
        app(AttributeObserver::class)->forgetCaches($entityTypes);

        return $attribute;
    }

    /** Insert a row straight into the tables, bypassing the model — simulates a write from another process. */
    private function insertRawAttribute(string $code, array $entityTypes): int
    {
        $id = DB::table('attributes')->insertGetId([
            'attribute_type_id' => $this->name->attribute_type_id,
            'code' => $code,
            'sort' => 2,
            'required' => false,
            'localizable' => false,
            'multiple' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
        ]);

        DB::table('attribute_entity_types')->insert(array_map(
            fn (string $entityType) => ['attribute_id' => $id, 'entity_type' => $entityType],
            $entityTypes
        ));

        return $id;
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
        $products = $this->registry->all('product');

        $this->assertTrue($products->has($this->name->id));
        $this->assertTrue($products->has($this->price->id));
    }

    public function test_for_entity_type_excludes_attributes_of_other_entity_types(): void
    {
        $products = $this->registry->all('product');

        $this->assertFalse($products->has($this->categoryCode->id));
    }

    public function test_for_entity_type_caches_are_kept_separate_per_entity_type(): void
    {
        $products = $this->registry->all('product');
        $categories = $this->registry->all('category');

        $this->assertCount(2, $products);
        $this->assertCount(1, $categories);
    }

    public function test_for_entity_type_is_cached_after_first_call(): void
    {
        $first = $this->registry->all('product');
        $second = $this->registry->all('product');

        $this->assertSame($first, $second);
    }

    public function test_warming_one_entity_type_does_not_warm_another(): void
    {
        $this->registry->all('product');

        $this->createAttribute($this->categoryCode->type, 'seo_title', 1, ['category']);

        // Not yet cached for 'category', so the freshly created row is visible.
        $this->assertCount(2, $this->registry->all('category'));
    }

    public function test_creating_an_attribute_automatically_invalidates_the_cache(): void
    {
        $first = $this->registry->all('product');

        $this->createAttribute($this->name->type, 'weight', 2, ['product']);

        $second = $this->registry->all('product');

        $this->assertNotSame($first, $second);
        $this->assertCount(3, $second);
    }

    public function test_has_returns_true_for_existing_id(): void
    {
        $this->assertTrue($this->registry->has('product', $this->name->id));
    }

    public function test_has_returns_false_for_missing_id(): void
    {
        $this->assertFalse($this->registry->has('product', 9999));
    }

    public function test_has_returns_false_when_id_belongs_to_another_entity_type(): void
    {
        $this->assertFalse($this->registry->has('product', $this->categoryCode->id));
    }

    public function test_get_returns_attribute_model(): void
    {
        $attribute = $this->registry->get('product', $this->price->id);

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('price', $attribute->code);
    }

    public function test_get_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->registry->get('product', 9999));
    }

    public function test_get_resolves_an_attribute_added_after_the_set_was_loaded(): void
    {
        $this->registry->all('product');

        // Written straight to the table: the observer that clears the registry runs in the process
        // performing the write, which on a long-running server is not this one.
        $id = $this->insertRawAttribute('weight', ['product']);

        $attribute = $this->registry->get('product', $id);

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('weight', $attribute->code);
    }

    public function test_get_keeps_a_resolved_miss_without_querying_again(): void
    {
        $this->registry->all('product');

        $id = $this->insertRawAttribute('weight', ['product']);

        $this->registry->get('product', $id);

        DB::enableQueryLog();
        $this->registry->get('product', $id);

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_get_does_not_query_again_for_an_id_that_does_not_exist(): void
    {
        $this->assertNull($this->registry->get('product', 9999));

        DB::enableQueryLog();

        $this->assertNull($this->registry->get('product', 9999));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_get_does_not_leak_an_attribute_of_another_entity_type(): void
    {
        $this->assertNull($this->registry->get('product', $this->categoryCode->id));
    }

    public function test_an_edit_made_elsewhere_is_picked_up_on_the_next_request(): void
    {
        $this->assertSame('price', $this->registry->get('product', $this->price->id)->code);

        // Written straight to the table: the observer clearing the registry runs in the process
        // performing the write, which on a long-running server is not this one.
        DB::table('attributes')->where('id', $this->price->id)->update(['code' => 'cost', 'updated_at' => now()->addMinute()]);

        $this->nextRequest();

        $this->assertSame('cost', $this->registry->get('product', $this->price->id)->code);
    }

    public function test_a_deletion_made_elsewhere_is_picked_up_on_the_next_request(): void
    {
        $this->assertCount(2, $this->registry->all('product'));

        DB::table('attributes')->where('id', $this->price->id)->delete();

        $this->nextRequest();

        $this->assertCount(1, $this->registry->all('product'));
    }

    public function test_a_delete_balanced_out_by_an_insert_is_still_picked_up(): void
    {
        $this->registry->all('product');

        DB::table('attributes')->where('id', $this->price->id)->delete();
        $this->insertRawAttribute('weight', ['product']);

        $this->nextRequest();

        $this->assertSame(
            ['name', 'weight'],
            $this->registry->all('product')->pluck('code')->sort()->values()->all()
        );
    }

    public function test_an_untouched_set_is_served_from_memory_without_reading_the_rows(): void
    {
        $this->registry->all('product');

        $this->nextRequest();

        DB::enableQueryLog();
        $this->registry->all('product');

        // One aggregate to confirm nothing moved — and no second query for the rows themselves.
        $this->assertSame(1, $this->queriesOnAttributes());
    }

    public function test_the_set_is_checked_once_per_request_however_often_it_is_read(): void
    {
        $this->registry->all('product');

        $this->nextRequest();

        DB::enableQueryLog();

        $this->registry->all('product');
        $this->registry->all('product');
        $this->registry->all('product');

        $this->assertSame(1, $this->queriesOnAttributes());
    }

    public function test_a_set_loaded_in_this_request_is_not_checked_again(): void
    {
        DB::enableQueryLog();

        $this->registry->all('product');
        $this->registry->all('product');

        // The stamp taken when loading, and the rows — nothing on top of that.
        $this->assertSame(2, $this->queriesOnAttributes());
    }

    public function test_forget_clears_cache_for_all_entity_types(): void
    {
        $this->registry->all('product');
        $this->registry->all('category');

        $this->registry->forget();

        $this->assertCount(2, $this->registry->all('product'));
        $this->assertCount(1, $this->registry->all('category'));
    }
}
