<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\AttributeRegistry;

class EntityAttributeReferenceDataRegistryTest extends FeatureTestCase
{
    public function test_attribute_relation_is_populated_from_registry_without_a_query(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $product = $this->createProduct();

        $value = EntityAttribute::create([
            'entity_id' => $product->id,
            'entity_type' => 'product',
            'attribute_id' => $attribute->id,
            'value_text' => 'Widget',
        ]);

        // Warm the registry once, outside of the assertion window.
        app(AttributeRegistry::class)->all();

        DB::enableQueryLog();

        $fetched = EntityAttribute::query()->find($value->id);

        $this->assertTrue($fetched->relationLoaded('attribute'));
        $this->assertSame('name', $fetched->attribute->code);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'attributes')));
    }

    public function test_repeated_fetches_across_separate_rows_share_the_same_attribute_query_count(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $productA = $this->createProduct('A');
        $productB = $this->createProduct('B');

        EntityAttribute::create(['entity_id' => $productA->id, 'entity_type' => 'product', 'attribute_id' => $attribute->id, 'value_text' => 'A']);
        EntityAttribute::create(['entity_id' => $productB->id, 'entity_type' => 'product', 'attribute_id' => $attribute->id, 'value_text' => 'B']);

        DB::enableQueryLog();

        // Two independent, unbatched fetches — mirrors a resource serializing each
        // row on its own (e.g. EntityAttributeResource::resolveAttributeValue()).
        EntityAttribute::query()->where('value_text', 'A')->first();
        EntityAttribute::query()->where('value_text', 'B')->first();

        $attributeQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "attributes"'));

        $this->assertCount(1, $attributeQueries);
    }

    public function test_updating_attribute_invalidates_the_registry(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $product = $this->createProduct();

        $value = EntityAttribute::create([
            'entity_id' => $product->id,
            'entity_type' => 'product',
            'attribute_id' => $attribute->id,
            'value_text' => 'Widget',
        ]);

        EntityAttribute::query()->find($value->id)->attribute;

        $attribute->update(['code' => 'renamed']);

        $fetched = EntityAttribute::query()->find($value->id);

        $this->assertSame('renamed', $fetched->attribute->code);
    }
}
