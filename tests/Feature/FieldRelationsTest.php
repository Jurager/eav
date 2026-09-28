<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

class FieldRelationsTest extends FeatureTestCase
{
    public function test_field_names_that_are_attribute_codes_address_attribute_values(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color']);
        $this->createAttribute($type, ['code' => 'size']);
        $product = $this->createProduct();

        $this->assertSame(['attribute_values' => ['color', 'size']], $product::fieldRelations(['color', 'name', 'size']));
    }

    public function test_field_names_that_are_not_attribute_codes_address_nothing(): void
    {
        $type = $this->createAttributeType('text');
        $this->createAttribute($type, ['code' => 'color']);
        $product = $this->createProduct();

        $this->assertSame([], $product::fieldRelations(['prices', 'type_id']));
        $this->assertSame([], $product::fieldRelations([]));
    }
}
