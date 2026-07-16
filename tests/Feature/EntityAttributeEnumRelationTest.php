<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Models\EntityAttribute;

/** value_integer can be shared with other field types, so enum() must stay scoped to its own attribute. */
class EntityAttributeEnumRelationTest extends FeatureTestCase
{
    public function test_enum_resolves_the_selected_option(): void
    {
        $selectType = $this->createAttributeType('select');
        $attribute = $this->createAttribute($selectType, ['code' => 'brand']);
        $blue = $this->createEnum($attribute, 'blue');
        $this->createEnum($attribute, 'red');

        $product = $this->createProduct();

        $value = EntityAttribute::create([
            'entity_id' => $product->id,
            'entity_type' => 'product',
            'attribute_id' => $attribute->id,
            'value_integer' => $blue->id,
        ]);

        $this->assertTrue($value->enum->is($blue));
    }

    public function test_enum_does_not_resolve_a_numeric_coincidence_from_a_different_attribute(): void
    {
        $selectType = $this->createAttributeType('select');
        $otherType = $this->createAttributeType('file');

        $selectAttribute = $this->createAttribute($selectType, ['code' => 'brand']);
        $fileAttribute = $this->createAttribute($otherType, ['code' => 'photo']);

        $enum = $this->createEnum($selectAttribute, 'blue');

        $product = $this->createProduct();

        // value_integer coincides with $enum->id by accident, not by reference.
        $fileValue = EntityAttribute::create([
            'entity_id' => $product->id,
            'entity_type' => 'product',
            'attribute_id' => $fileAttribute->id,
            'value_integer' => $enum->id,
        ]);

        $this->assertNull($fileValue->enum, 'A non-select attribute must never resolve to another attribute\'s enum option, even on an id collision.');
    }

    public function test_eager_loading_keeps_each_row_scoped_to_its_own_attribute(): void
    {
        $selectType = $this->createAttributeType('select');
        $otherType = $this->createAttributeType('file');

        $selectAttribute = $this->createAttribute($selectType, ['code' => 'brand']);
        $fileAttribute = $this->createAttribute($otherType, ['code' => 'photo']);

        $blue = $this->createEnum($selectAttribute, 'blue');

        $productA = $this->createProduct('A');
        $productB = $this->createProduct('B');

        $selectValue = EntityAttribute::create([
            'entity_id' => $productA->id,
            'entity_type' => 'product',
            'attribute_id' => $selectAttribute->id,
            'value_integer' => $blue->id,
        ]);

        $fileValue = EntityAttribute::create([
            'entity_id' => $productB->id,
            'entity_type' => 'product',
            'attribute_id' => $fileAttribute->id,
            'value_integer' => $blue->id,
        ]);

        $loaded = EntityAttribute::query()
            ->whereIn('id', [$selectValue->id, $fileValue->id])
            ->with('enum')
            ->get()
            ->keyBy('id');

        $this->assertTrue($loaded[$selectValue->id]->enum->is($blue));
        $this->assertNull($loaded[$fileValue->id]->enum);
    }
}
