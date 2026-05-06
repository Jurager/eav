<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\NumberField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class NumberFieldTest extends TestCase
{
    private LocaleRegistry $localeRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localeRegistry = Mockery::mock(LocaleRegistry::class);
        $this->localeRegistry->shouldReceive('has')->andReturn(true);
        $this->localeRegistry->shouldReceive('default')->andReturn(1);
    }

    private function makeAttribute(array $attributes = []): Attribute
    {
        return (new Attribute)->forceFill(array_merge([
            'code'        => 'price',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): NumberField
    {
        return new NumberField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_float(): void
    {
        $this->assertSame(Field::STORAGE_FLOAT, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // fill() — validation
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_integer(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(42));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_float(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(3.14));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_numeric_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('99.5'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_rejects_non_numeric_string(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill('abc'));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // Normalization — integer vs float
    // -----------------------------------------------------------------------

    public function test_whole_number_normalizes_to_integer(): void
    {
        $field = $this->makeField();
        $field->fill(5);

        $this->assertSame(5, $field->value());
        $this->assertIsInt($field->value());
    }

    public function test_float_string_normalizes_to_float(): void
    {
        $field = $this->makeField();
        $field->fill('5.5');

        $this->assertSame(5.5, $field->value());
        $this->assertIsFloat($field->value());
    }

    public function test_float_with_zero_fraction_normalizes_to_integer(): void
    {
        $field = $this->makeField();
        $field->fill(5.0);

        $this->assertIsInt($field->value());
        $this->assertSame(5, $field->value());
    }

    public function test_numeric_string_whole_normalizes_to_integer(): void
    {
        $field = $this->makeField();
        $field->fill('100');

        $this->assertSame(100, $field->value());
        $this->assertIsInt($field->value());
    }

    // -----------------------------------------------------------------------
    // value() / set() / forget()
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_set_and_value(): void
    {
        $field = $this->makeField();
        $field->set(42);

        $this->assertSame(42, $field->value());
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill(10);
        $field->forget();

        $this->assertNull($field->value());
    }

    // -----------------------------------------------------------------------
    // toStorage()
    // -----------------------------------------------------------------------

    public function test_to_storage_contains_normalized_value(): void
    {
        $field = $this->makeField();
        $field->fill('7');

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertSame(7, $storage[0]['value']);
    }
}
