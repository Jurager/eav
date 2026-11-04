<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Jurager\Eav\Attributes\AttributeManager;
use Jurager\Eav\Attributes\EntitySchema;
use Jurager\Eav\Facades\Attributes;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class AttributesFacadeTest extends FeatureTestCase
{
    public function test_for_an_entity_returns_its_manager(): void
    {
        $product = $this->createProduct();

        $this->assertInstanceOf(AttributeManager::class, Attributes::for($product));
        $this->assertSame($product, Attributes::for($product)->entity());
    }

    public function test_schema_of_an_entity_type_lists_its_definitions(): void
    {
        $this->createAttribute($this->createAttributeType(), ['code' => 'name']);

        $schema = Attributes::schema('product');

        $this->assertInstanceOf(EntitySchema::class, $schema);
        $this->assertSame(['name'], $schema->attributes()->pluck('code')->all());
    }

    public function test_sync_persists_values_for_multiple_entities(): void
    {
        $this->createAttribute($this->createAttributeType(), ['code' => 'title']);
        $p1 = $this->createProduct('P1');
        $p2 = $this->createProduct('P2');

        Attributes::sync(collect([
            ['entity' => $p1, 'data' => ['title' => 'Alpha']],
            ['entity' => $p2, 'data' => ['title' => 'Beta']],
        ]));

        $this->assertSame('Alpha', $p1->fresh()->eav()->value('title'));
        $this->assertSame('Beta', $p2->fresh()->eav()->value('title'));
    }

    public function test_query_finds_entities_by_attribute_value(): void
    {
        $this->createAttribute($this->createAttributeType(), ['code' => 'sku']);
        $product = $this->createProduct();
        $product->eav()->set('sku', 'A-1')->save('sku');

        $this->assertTrue($product->is(Attributes::query('product')->findBy('sku', 'A-1')));
        $this->assertTrue($product->is(Attributes::query(\Jurager\Eav\Tests\Fixtures\Product::class)->findBy('sku', 'A-1')));
    }
}
