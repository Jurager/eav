<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Validation\ValidationException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\AttributeValidator;

class AttributeValidatorTest extends FeatureTestCase
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
    // validate() — happy path
    // -----------------------------------------------------------------------

    public function test_validate_returns_filled_fields_for_valid_input(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);
        $this->createAttribute($this->numberType, ['code' => 'price']);

        $product = $this->createProduct();

        $fields = $product->validate([
            ['code' => 'title', 'values' => 'My Product'],
            ['code' => 'price', 'values' => 99.99],
        ]);

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayHasKey('price', $fields);
        $this->assertInstanceOf(Field::class, $fields['title']);
    }

    public function test_validate_ignores_unknown_attribute_codes(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();

        $fields = $product->validate([
            ['code' => 'title', 'values' => 'Widget'],
            ['code' => 'ghost', 'values' => 'ignored'],
        ]);

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayNotHasKey('ghost', $fields);
    }

    public function test_validate_accepts_null_values(): void
    {
        $this->createAttribute($this->textType, ['code' => 'title']);

        $product = $this->createProduct();

        $fields = $product->validate([
            ['code' => 'title', 'values' => null],
        ]);

        $this->assertArrayHasKey('title', $fields);
    }

    // -----------------------------------------------------------------------
    // Mandatory field validation
    // -----------------------------------------------------------------------

    public function test_validate_throws_for_mandatory_field_with_null_value(): void
    {
        $this->createAttribute($this->textType, [
            'code'      => 'title',
            'mandatory' => true,
        ]);

        $product = $this->createProduct();

        $this->expectException(ValidationException::class);

        $product->validate([
            ['code' => 'title', 'values' => null],
        ]);
    }

    public function test_validate_throws_for_mandatory_field_not_included_in_input(): void
    {
        $this->createAttribute($this->textType, [
            'code'      => 'title',
            'mandatory' => true,
        ]);

        $product = $this->createProduct();

        $this->expectException(ValidationException::class);

        $product->validate([]);
    }

    public function test_validate_passes_when_mandatory_field_has_value(): void
    {
        $this->createAttribute($this->textType, [
            'code'      => 'title',
            'mandatory' => true,
        ]);

        $product = $this->createProduct();

        $fields = $product->validate([
            ['code' => 'title', 'values' => 'My Title'],
        ]);

        $this->assertArrayHasKey('title', $fields);
    }

    // -----------------------------------------------------------------------
    // Invalid value validation
    // -----------------------------------------------------------------------

    public function test_validate_throws_for_invalid_field_value(): void
    {
        $this->createAttribute($this->textType, ['code' => 'name']);

        $product = $this->createProduct();

        $this->expectException(ValidationException::class);

        // Integer is not valid for TextField
        $product->validate([
            ['code' => 'name', 'values' => 42],
        ]);
    }

    // -----------------------------------------------------------------------
    // Unique field validation
    // -----------------------------------------------------------------------

    public function test_validate_throws_for_duplicate_unique_field_value(): void
    {
        $this->createAttribute($this->textType, [
            'code'   => 'sku',
            'unique' => true,
        ]);

        $p1 = $this->createProduct('P1');
        $p1->eav()->set('sku', 'SKU-001')->save('sku');

        // New product attempts to use the same SKU
        $p2 = $this->createProduct('P2');

        $this->expectException(ValidationException::class);

        $p2->validate([
            ['code' => 'sku', 'values' => 'SKU-001'],
        ]);
    }

    public function test_validate_allows_same_unique_value_for_same_entity(): void
    {
        $this->createAttribute($this->textType, [
            'code'   => 'sku',
            'unique' => true,
        ]);

        $product = $this->createProduct();
        $product->eav()->set('sku', 'SKU-001')->save('sku');

        // Same entity re-validates its own SKU — should not conflict
        $fields = $product->validate([
            ['code' => 'sku', 'values' => 'SKU-001'],
        ]);

        $this->assertArrayHasKey('sku', $fields);
    }

    public function test_validate_error_contains_attribute_code_as_key(): void
    {
        $this->createAttribute($this->textType, ['code' => 'description']);

        $product = $this->createProduct();

        try {
            $product->validate([
                ['code' => 'description', 'values' => 12345],
            ]);
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
        }
    }

    // -----------------------------------------------------------------------
    // AttributeValidator::registerUniqueScope()
    // -----------------------------------------------------------------------

    public function test_register_unique_scope_restricts_uniqueness_check(): void
    {
        $this->createAttribute($this->textType, [
            'code'   => 'ref',
            'unique' => true,
        ]);

        // Register a scope that makes the uniqueness check always exclude everything
        // (empty result set = no conflict ever possible)
        AttributeValidator::registerUniqueScope('product', 'ref', function ($query) {
            $query->whereRaw('1 = 0');
        });

        $p1 = $this->createProduct('P1');
        $p1->eav()->set('ref', 'REF-001')->save('ref');

        $p2 = $this->createProduct('P2');

        // With the scope forcing an empty conflict query, validation should pass
        $fields = $p2->validate([
            ['code' => 'ref', 'values' => 'REF-001'],
        ]);

        $this->assertArrayHasKey('ref', $fields);
    }
}
