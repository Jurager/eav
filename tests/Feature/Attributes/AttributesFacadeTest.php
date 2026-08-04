<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Attributes;

use Jurager\Eav\Facades\Attributes;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class AttributesFacadeTest extends FeatureTestCase
{
    public function test_for_an_entity_type_string_returns_a_schema_only_manager(): void
    {
        $this->createAttribute($this->createAttributeType(), ['code' => 'name']);

        $this->assertInstanceOf(AttributeManager::class, Attributes::for('product'));
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

    public function test_schema_returns_a_manager_for_a_preloaded_attribute_collection(): void
    {
        $this->createAttribute($this->createAttributeType(), ['code' => 'title']);

        $manager = Attributes::schema(Attribute::with('type')->get());

        $this->assertInstanceOf(AttributeManager::class, $manager);
        $this->assertNotNull($manager->field('title'));
    }
}
