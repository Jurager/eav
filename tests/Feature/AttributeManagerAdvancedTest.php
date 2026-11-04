<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Attributes\AttributeManager;
use Jurager\Eav\Facades\Attributes;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Tests\Fixtures\Product;

class AttributeManagerAdvancedTest extends FeatureTestCase
{
    private AttributeType $textType;

    private AttributeType $numberType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $this->textType = $this->createAttributeType('text');
        $this->numberType = $this->createAttributeType('number');
    }

    // -----------------------------------------------------------------------
    // values() — returns entity_attribute records with typed value
    // -----------------------------------------------------------------------

    public function test_values_returns_empty_collection_before_save(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();

        $values = $product->eav()->values();

        $this->assertCount(0, $values);
    }

    public function test_values_returns_records_with_resolved_value_property(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();
        $product->eav()->set('title', 'My Widget')->save('title');

        $values = $product->fresh()->eav()->values();

        $this->assertCount(1, $values);
        $this->assertSame('My Widget', $values->first()->value);
    }

    public function test_values_filters_by_codes(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();
        $product->eav()->set('title', 'Widget')->save('title');
        $product->eav()->set('price', 99)->save('price');

        $values = $product->fresh()->eav()->values(['title']);

        $this->assertCount(1, $values);
    }

    // -----------------------------------------------------------------------
    // findBy() — single entity lookup by attribute value
    // -----------------------------------------------------------------------

    public function test_find_by_returns_matching_entity(): void
    {
        $this->createAttribute($this->textType, ['code' => 'sku']);

        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        $p1->eav()->set('sku', 'SKU-001')->save('sku');
        $p2->eav()->set('sku', 'SKU-002')->save('sku');

        $found = Attributes::query(Product::class)->findBy('sku', 'SKU-002');

        $this->assertNotNull($found);
        $this->assertSame($p2->id, $found->id);
    }

    public function test_find_by_returns_null_when_not_found(): void
    {
        $this->createAttribute($this->textType, ['code' => 'sku']);

        $product = $this->createProduct();
        $product->eav()->set('sku', 'SKU-001')->save('sku');

        $found = Attributes::query(Product::class)->findBy('sku', 'NONEXISTENT');

        $this->assertNull($found);
    }

    // -----------------------------------------------------------------------
    // findAllBy() — multiple entities lookup
    // -----------------------------------------------------------------------

    public function test_find_all_by_returns_all_matching_entities(): void
    {
        $this->createAttribute($this->textType, ['code' => 'status']);

        $active1 = $this->createProduct('Active 1');
        $active2 = $this->createProduct('Active 2');
        $inactive = $this->createProduct('Inactive');

        $active1->eav()->set('status', 'active')->save('status');
        $active2->eav()->set('status', 'active')->save('status');
        $inactive->eav()->set('status', 'inactive')->save('status');

        $results = Attributes::query(Product::class)->findAllBy('status', 'active');

        $this->assertCount(2, $results);
        $ids = $results->pluck('id')->all();
        $this->assertContains($active1->id, $ids);
        $this->assertContains($active2->id, $ids);
    }

    // -----------------------------------------------------------------------
    // findWhereIn() — keyed collection lookup
    // -----------------------------------------------------------------------

    public function test_find_where_in_returns_collection_keyed_by_attribute_value(): void
    {
        $this->createAttribute($this->textType, ['code' => 'ref']);

        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        $p1->eav()->set('ref', 'REF-A')->save('ref');
        $p2->eav()->set('ref', 'REF-B')->save('ref');

        $results = Attributes::query(Product::class)->findWhereIn('ref', ['REF-A', 'REF-B']);

        $this->assertCount(2, $results);
        $this->assertSame($p1->id, $results['REF-A']->id);
        $this->assertSame($p2->id, $results['REF-B']->id);
    }

    public function test_find_where_in_returns_empty_collection_when_no_matches(): void
    {
        $this->createAttribute($this->textType, ['code' => 'ref']);

        $product = $this->createProduct();
        $product->eav()->set('ref', 'REF-A')->save('ref');

        $results = Attributes::query(Product::class)->findWhereIn('ref', ['NONEXISTENT']);

        $this->assertCount(0, $results);
    }

    // -----------------------------------------------------------------------
    // indexData() — searchable attribute values for Scout
    // -----------------------------------------------------------------------

    public function test_index_data_returns_empty_when_no_searchable_attributes(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title', 'searchable' => false]);

        $product = $this->createProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $data = $product->fresh()->eav()->indexData();

        $this->assertSame([], $data);
    }

    public function test_index_data_includes_searchable_attribute_values(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title', 'searchable' => true]);

        $product = $this->createProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $data = $product->fresh()->eav()->indexData();

        $this->assertArrayHasKey('attributes', $data);
        $this->assertArrayHasKey('title', $data['attributes']);
        $this->assertSame('Widget', $data['attributes']['title']);
    }

    public function test_index_data_is_memoized(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title', 'searchable' => true]);

        $product = $this->createProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $product = $product->fresh();

        $first = $product->eav()->indexData();
        $second = $product->eav()->indexData();

        $this->assertSame($first, $second);
    }

    // -----------------------------------------------------------------------
    // Attributes::schema()
    // -----------------------------------------------------------------------

    public function test_schema_of_an_entity_builds_field_instances(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $fields = Attributes::schema($this->createProduct())->fields();

        $this->assertArrayHasKey('title', $fields);
        $this->assertFalse($fields['title']->isFilled());
    }

    public function test_schema_of_an_entity_type_builds_field_instances(): void
    {
        $this->createAttribute($this->textType, ['code' => 'price']);

        $this->assertArrayHasKey('price', Attributes::schema('product')->fields());
        $this->assertArrayHasKey('price', Attributes::schema(Product::class)->fields());
    }

    // -----------------------------------------------------------------------
    // subquery() / additional operators
    // -----------------------------------------------------------------------

    public function test_subquery_returns_null_for_unknown_code(): void
    {
        $product = $this->createProduct();
        $this->assertNull(Attributes::query(Product::class)->subquery('nonexistent', 'value'));
    }

    public function test_where_attribute_with_not_equal_operator(): void
    {
        $this->createAttribute($this->textType, ['code' => 'status']);

        $active = $this->createProduct('active');
        $inactive = $this->createProduct('inactive');

        $active->eav()->set('status', 'active')->save('status');
        $inactive->eav()->set('status', 'inactive')->save('status');

        $results = Product::whereAttribute('status', 'active', '!=')->get();

        $this->assertCount(1, $results);
        $this->assertSame($inactive->id, $results->first()->id);
    }

    public function test_where_attribute_with_null_operator(): void
    {
        $attr = $this->createAttribute($this->numberType, ['code' => 'weight']);

        $withWeight = $this->createProduct('with');
        $withoutWeight = $this->createProduct('without');

        $withWeight->eav()->set('weight', 5)->save('weight');

        // Insert a row with explicit null value so the 'null' operator can match it
        DB::table('entity_attribute')->insert([
            'entity_type' => 'product',
            'entity_id' => $withoutWeight->id,
            'attribute_id' => $attr->id,
            'value_float' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = Product::whereAttribute('weight', null, 'null')->get();

        $this->assertCount(1, $results);
        $this->assertSame($withoutWeight->id, $results->first()->id);
    }
}
