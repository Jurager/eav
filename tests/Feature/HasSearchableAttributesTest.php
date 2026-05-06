<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;

class HasSearchableAttributesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['searchable_product' => SearchableProduct::class]);

        $this->createLocale('en');
    }

    protected function tearDown(): void
    {
        Relation::morphMap(['searchable_product' => null]);
        parent::tearDown();
    }

    private function createSearchableProduct(string $name = 'Widget'): SearchableProduct
    {
        return SearchableProduct::create(['name' => $name]);
    }

    // -----------------------------------------------------------------------
    // toSearchableArray()
    // -----------------------------------------------------------------------

    public function test_to_searchable_array_contains_id(): void
    {
        $product = $this->createSearchableProduct();
        $array   = $product->toSearchableArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertSame((string) $product->id, $array['id']);
    }

    public function test_to_searchable_array_contains_no_attributes_key_when_none_searchable(): void
    {
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, ['code' => 'title', 'searchable' => false]);

        $product = $this->createSearchableProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $array = $product->fresh()->toSearchableArray();

        $this->assertArrayNotHasKey('attributes', $array);
    }

    public function test_to_searchable_array_includes_searchable_attribute_values(): void
    {
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, [
            'code'       => 'description',
            'searchable' => true,
            'entity_type' => 'searchable_product',
        ]);

        $product = $this->createSearchableProduct();
        $product->eav()->set('description', 'A great widget')->save('description');

        $array = $product->fresh()->toSearchableArray();

        $this->assertArrayHasKey('attributes', $array);
        $this->assertSame('A great widget', $array['attributes']['description']);
    }

    // -----------------------------------------------------------------------
    // shouldBeSearchable()
    // -----------------------------------------------------------------------

    public function test_should_be_searchable_returns_false_when_no_searchable_values(): void
    {
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, [
            'code'        => 'sku',
            'searchable'  => false,
            'entity_type' => 'searchable_product',
        ]);

        $product = $this->createSearchableProduct();
        $product->eav()->set('sku', 'SKU-001')->save('sku');

        $this->assertFalse($product->fresh()->shouldBeSearchable());
    }

    public function test_should_be_searchable_returns_true_when_searchable_value_exists(): void
    {
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, [
            'code'        => 'title',
            'searchable'  => true,
            'entity_type' => 'searchable_product',
        ]);

        $product = $this->createSearchableProduct();
        $product->eav()->set('title', 'Widget')->save('title');

        $this->assertTrue($product->fresh()->shouldBeSearchable());
    }

    public function test_should_be_searchable_returns_false_when_no_values_saved(): void
    {
        $textType = $this->createAttributeType('text');
        $this->createAttribute($textType, [
            'code'        => 'title',
            'searchable'  => true,
            'entity_type' => 'searchable_product',
        ]);

        $product = $this->createSearchableProduct();

        $this->assertFalse($product->shouldBeSearchable());
    }
}
