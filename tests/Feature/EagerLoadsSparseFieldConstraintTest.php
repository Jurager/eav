<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Models\EntityAttribute;

class EagerLoadsSparseFieldConstraintTest extends FeatureTestCase
{
    public function test_eager_loads_returns_nothing_when_attribute_values_was_not_included(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color']);
        $product = $this->createProduct();

        $this->assertSame([], $product->eagerLoads([], ['color']));
        $this->assertSame([], $product->eagerLoads(['prices'], ['color']));
    }

    public function test_eager_loads_has_no_constraint_when_no_sparse_fields_were_requested(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color']);
        $product = $this->createProduct();

        // No "attribute_values" key at all — unconstrained, unchanged from before sparse fields
        // existed. The fixture declares a parent relation, so inheritedValueRelations() still
        // contributes its own (unrelated) entry.
        $this->assertSame(['parent.attribute_values.attribute.type'], $product->eagerLoads(['attribute_values']));
        $this->assertSame(['parent.attribute_values.attribute.type'], $product->eagerLoads(['attribute_values'], null));
        $this->assertArrayNotHasKey('attribute_values', $product->eagerLoads(['attribute_values']));
    }

    public function test_eager_loads_constrains_attribute_values_to_the_requested_codes(): void
    {
        $type = $this->createAttributeType('text');
        $color = $this->createAttribute($type, ['code' => 'color']);
        $size = $this->createAttribute($type, ['code' => 'size']);
        $weight = $this->createAttribute($type, ['code' => 'weight']);

        $product = $this->createProduct();
        EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $color->id, 'value_text' => 'red']);
        EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $size->id, 'value_text' => 'M']);
        EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $weight->id, 'value_text' => '1kg']);

        $relations = $product->eagerLoads(['attribute_values'], ['color', 'size']);

        $this->assertArrayHasKey('attribute_values', $relations);
        $this->assertInstanceOf(\Closure::class, $relations['attribute_values']);

        $product->load(['attribute_values' => $relations['attribute_values']]);

        $this->assertCount(2, $product->attribute_values);
        $this->assertSame(['color', 'size'], $product->attribute_values->map(fn ($ea) => $ea->attribute->code)->sort()->values()->all());
    }

    public function test_eager_loads_narrows_to_nothing_when_no_requested_field_is_a_real_attribute_code(): void
    {
        $type = $this->createAttributeType('text');
        $color = $this->createAttribute($type, ['code' => 'color']);

        $product = $this->createProduct();
        EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $color->id, 'value_text' => 'red']);

        // "name" and "type_id" aren't EAV codes for this entity type — no rows should match.
        $relations = $product->eagerLoads(['attribute_values'], ['name', 'type_id']);

        $product->load(['attribute_values' => $relations['attribute_values']]);

        $this->assertCount(0, $product->attribute_values);
    }

    public function test_eager_loads_constraint_does_not_query_the_whole_entity_types_schema(): void
    {
        $type = $this->createAttributeType('text');
        $color = $this->createAttribute($type, ['code' => 'color']);

        // Warm the registry once, outside of the assertion window — mirrors a request where
        // something already touched the entity type's attribute schema.
        $product = $this->createProduct();
        EntityAttribute::create(['entity_id' => $product->id, 'entity_type' => 'product', 'attribute_id' => $color->id, 'value_text' => 'red']);
        $product->eagerLoads(['attribute_values'], ['color']);

        DB::enableQueryLog();

        $relations = $product->eagerLoads(['attribute_values'], ['color']);
        $product->load(['attribute_values' => $relations['attribute_values']]);

        // Just the entity_attribute rows themselves — the attribute schema is already cached,
        // so resolving "color" -> its id must not re-query the attributes table.
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "attributes"')));

        DB::disableQueryLog();
    }
}
