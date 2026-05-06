<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Tests\Fixtures\Product;

class HasAttributesTest extends FeatureTestCase
{
    private AttributeType $textType;

    private AttributeType $numberType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $this->textType   = $this->createAttributeType('text');
        $this->numberType = $this->createAttributeType('number');
    }

    // -----------------------------------------------------------------------
    // eav() — returns and caches AttributeManager
    // -----------------------------------------------------------------------

    public function test_eav_returns_attribute_manager(): void
    {
        $product = $this->createProduct();

        $this->assertInstanceOf(AttributeManager::class, $product->eav());
    }

    public function test_eav_is_cached_per_instance(): void
    {
        $product = $this->createProduct();

        $this->assertSame($product->eav(), $product->eav());
    }

    public function test_different_instances_have_different_managers(): void
    {
        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        $this->assertNotSame($p1->eav(), $p2->eav());
    }

    // -----------------------------------------------------------------------
    // availableAttributes()
    // -----------------------------------------------------------------------

    public function test_available_attributes_returns_all_attributes_for_entity_type(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();
        $attributes = $product->availableAttributes();

        $this->assertCount(2, $attributes);
    }

    public function test_available_attributes_returns_empty_when_none_defined(): void
    {
        $product = $this->createProduct();

        $this->assertCount(0, $product->availableAttributes());
    }

    // -----------------------------------------------------------------------
    // attribute_values() relation
    // -----------------------------------------------------------------------

    public function test_attribute_values_returns_empty_relation_before_save(): void
    {
        $product = $this->createProduct();

        $this->assertCount(0, $product->attribute_values);
    }

    public function test_attribute_values_returns_rows_after_save(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $this->assertCount(1, $product->fresh()->attribute_values);
    }

    // -----------------------------------------------------------------------
    // attribute_relation() relation
    // -----------------------------------------------------------------------

    public function test_attribute_relation_returns_attributes_with_pivot(): void
    {
        $attr = $this->createAttribute($this->textType, ['code' => 'title']);
        $product = $this->createProduct();

        $product->eav()->set('title', 'My Title')->save('title');

        $pivot = $product->fresh()->attribute_relation->first();

        $this->assertNotNull($pivot);
        $this->assertSame($attr->id, $pivot->id);
    }

    // -----------------------------------------------------------------------
    // scopeWhereAttribute()
    // -----------------------------------------------------------------------

    public function test_where_attribute_filters_by_exact_value(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        $p1->eav()->set('title', 'Alpha')->save('title');
        $p2->eav()->set('title', 'Beta')->save('title');

        $results = Product::whereAttribute('title', 'Alpha')->get();

        $this->assertCount(1, $results);
        $this->assertSame($p1->id, $results->first()->id);
    }

    public function test_where_attribute_returns_empty_when_no_match(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $results = Product::whereAttribute('title', 'Nonexistent')->get();

        $this->assertCount(0, $results);
    }

    // -----------------------------------------------------------------------
    // scopeWhereAttributeLike()
    // -----------------------------------------------------------------------

    public function test_where_attribute_like_filters_by_partial_match(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        $p1->eav()->set('title', 'Blue Widget')->save('title');
        $p2->eav()->set('title', 'Red Gadget')->save('title');

        $results = Product::whereAttributeLike('title', 'Widget')->get();

        $this->assertCount(1, $results);
        $this->assertSame($p1->id, $results->first()->id);
    }

    // -----------------------------------------------------------------------
    // scopeWhereAttributeBetween()
    // -----------------------------------------------------------------------

    public function test_where_attribute_between_filters_by_range(): void
    {
        $this->createAttribute($this->numberType, ['code' => 'price', 'filterable' => true]);

        $cheap  = $this->createProduct('cheap');
        $mid    = $this->createProduct('mid');
        $expensive = $this->createProduct('expensive');

        $cheap->eav()->set('price', 5)->save('price');
        $mid->eav()->set('price', 50)->save('price');
        $expensive->eav()->set('price', 500)->save('price');

        $results = Product::whereAttributeBetween('price', 10, 100)->get();

        $this->assertCount(1, $results);
        $this->assertSame($mid->id, $results->first()->id);
    }

    // -----------------------------------------------------------------------
    // scopeWhereAttributeIn()
    // -----------------------------------------------------------------------

    public function test_where_attribute_in_filters_by_value_set(): void
    {
        $this->createAttribute($this->textType, ['code' => 'status']);

        $active   = $this->createProduct('active');
        $inactive = $this->createProduct('inactive');
        $pending  = $this->createProduct('pending');

        $active->eav()->set('status', 'active')->save('status');
        $inactive->eav()->set('status', 'inactive')->save('status');
        $pending->eav()->set('status', 'pending')->save('status');

        $results = Product::whereAttributeIn('status', ['active', 'pending'])->get();

        $this->assertCount(2, $results);
        $ids = $results->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertContains($pending->id, $ids);
    }

    // -----------------------------------------------------------------------
    // scopeWhereAttributes() — AND logic
    // -----------------------------------------------------------------------

    public function test_where_attributes_combines_multiple_conditions_with_and(): void
    {
        $this->createAttribute($this->textType, ['code' => 'status']);
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $match    = $this->createProduct('match');
        $no_match = $this->createProduct('no_match');

        $match->eav()->set('status', 'active')->save('status');
        $match->eav()->set('price', 10)->save('price');

        $no_match->eav()->set('status', 'active')->save('status');
        $no_match->eav()->set('price', 999)->save('price');

        $results = Product::whereAttributes([
            ['code' => 'status', 'value' => 'active'],
            ['code' => 'price', 'value' => 10],
        ])->get();

        $this->assertCount(1, $results);
        $this->assertSame($match->id, $results->first()->id);
    }

    // -----------------------------------------------------------------------
    // numericRanges()
    // -----------------------------------------------------------------------

    public function test_numeric_ranges_returns_min_max_for_filterable_number_attribute(): void
    {
        $this->createAttribute($this->numberType, [
            'code'       => 'price',
            'filterable' => true,
        ]);

        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');
        $p3 = $this->createProduct('P3');

        $p1->eav()->set('price', 10)->save('price');
        $p2->eav()->set('price', 50)->save('price');
        $p3->eav()->set('price', 200)->save('price');

        $ranges = $p1->eav()->numericRanges([$p1->id, $p2->id, $p3->id]);

        $this->assertArrayHasKey('price', $ranges);
        $this->assertSame(10.0, $ranges['price']['min']);
        $this->assertSame(200.0, $ranges['price']['max']);
    }

    public function test_numeric_ranges_returns_empty_for_no_entity_ids(): void
    {
        $product = $this->createProduct();

        $ranges = $product->eav()->numericRanges([]);

        $this->assertSame([], $ranges);
    }
}
