<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Jurager\Eav\Exceptions\InvalidFieldTypeException;
use Jurager\Eav\Fields\BooleanField;
use Jurager\Eav\Fields\NumberField;
use Jurager\Eav\Fields\TextField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Tests\TestCase;

class FieldTypeRegistryTest extends TestCase
{
    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(FieldTypeRegistry::class);
    }

    // -----------------------------------------------------------------------
    // has()
    // -----------------------------------------------------------------------

    public function test_has_returns_true_for_registered_type(): void
    {
        $this->assertTrue($this->registry->has('text'));
        $this->assertTrue($this->registry->has('number'));
        $this->assertTrue($this->registry->has('boolean'));
        $this->assertTrue($this->registry->has('select'));
    }

    public function test_has_returns_false_for_unknown_type(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    // -----------------------------------------------------------------------
    // resolve()
    // -----------------------------------------------------------------------

    public function test_resolve_returns_correct_class_for_text(): void
    {
        $this->assertSame(TextField::class, $this->registry->resolve('text'));
    }

    public function test_resolve_returns_correct_class_for_number(): void
    {
        $this->assertSame(NumberField::class, $this->registry->resolve('number'));
    }

    public function test_resolve_returns_correct_class_for_boolean(): void
    {
        $this->assertSame(BooleanField::class, $this->registry->resolve('boolean'));
    }

    public function test_resolve_throws_for_unknown_type(): void
    {
        $this->expectException(InvalidFieldTypeException::class);

        $this->registry->resolve('nonexistent');
    }

    // -----------------------------------------------------------------------
    // register()
    // -----------------------------------------------------------------------

    public function test_register_adds_custom_type(): void
    {
        $this->registry->register('custom_text', TextField::class);

        $this->assertTrue($this->registry->has('custom_text'));
        $this->assertSame(TextField::class, $this->registry->resolve('custom_text'));
    }

    public function test_register_throws_for_non_field_class(): void
    {
        $this->expectException(InvalidFieldTypeException::class);

        $this->registry->register('bad', Attribute::class);
    }

    // -----------------------------------------------------------------------
    // all()
    // -----------------------------------------------------------------------

    public function test_all_returns_all_registered_types(): void
    {
        $all = $this->registry->all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('text', $all);
        $this->assertArrayHasKey('number', $all);
        $this->assertArrayHasKey('boolean', $all);
        $this->assertArrayHasKey('select', $all);
        $this->assertArrayHasKey('date', $all);
    }

    // -----------------------------------------------------------------------
    // make()
    // -----------------------------------------------------------------------

    public function test_make_creates_correct_field_instance(): void
    {
        $type = new AttributeType();
        $type->code = 'text';

        $attribute = (new Attribute())->forceFill([
            'code'        => 'title',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ]);
        $attribute->setRelation('type', $type);

        $field = $this->registry->make($attribute);

        $this->assertInstanceOf(TextField::class, $field);
    }

    public function test_make_creates_number_field(): void
    {
        $type = new AttributeType();
        $type->code = 'number';

        $attribute = (new Attribute())->forceFill([
            'code'        => 'price',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ]);
        $attribute->setRelation('type', $type);

        $field = $this->registry->make($attribute);

        $this->assertInstanceOf(NumberField::class, $field);
    }

    public function test_make_throws_when_type_relation_not_loaded(): void
    {
        $attribute = (new Attribute())->forceFill([
            'code'        => 'title',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ]);

        $this->expectException(InvalidFieldTypeException::class);

        $this->registry->make($attribute);
    }
}
