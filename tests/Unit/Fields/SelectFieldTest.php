<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\SelectField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class SelectFieldTest extends TestCase
{
    private LocaleRegistry $localeRegistry;

    private EnumRegistry $enumRegistry;

    /** Valid enum IDs for the test attribute (id=1). */
    private array $validEnumIds = [10 => true, 20 => true, 30 => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->localeRegistry = Mockery::mock(LocaleRegistry::class);
        $this->localeRegistry->shouldReceive('has')->andReturn(true);
        $this->localeRegistry->shouldReceive('default')->andReturn(1);
        $this->localeRegistry->shouldReceive('ids')->andReturn([1]);

        $this->enumRegistry = Mockery::mock(EnumRegistry::class);
        $this->enumRegistry->shouldReceive('resolve')->andReturn($this->validEnumIds);
    }

    private function makeAttribute(array $attributes = []): Attribute
    {
        $attr = (new Attribute)->forceFill(array_merge([
            'id'          => 1,
            'code'        => 'color',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));

        $attr->setRelation('enums', collect());

        return $attr;
    }

    private function makeField(array $attributes = []): SelectField
    {
        return new SelectField($this->makeAttribute($attributes), $this->localeRegistry, $this->enumRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_integer(): void
    {
        $this->assertSame(Field::STORAGE_INTEGER, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // fill() — single select
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
        $this->assertFalse($field->isFilled());
    }

    public function test_fill_accepts_valid_enum_id(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(10));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_valid_enum_id_as_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('20'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_rejects_invalid_enum_id(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(999));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_non_numeric_value(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill('red'));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // fill() — multiple select
    // -----------------------------------------------------------------------

    public function test_fill_accepts_array_of_valid_ids_for_multiple(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertTrue($field->fill([10, 20]));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_rejects_non_array_for_multiple(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertFalse($field->fill(10));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_array_with_invalid_id_for_multiple(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertFalse($field->fill([10, 999]));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // value()
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_value_returns_integer_for_single_select(): void
    {
        $field = $this->makeField();
        $field->fill(10);

        $this->assertSame(10, $field->value());
        $this->assertIsInt($field->value());
    }

    public function test_value_returns_array_for_multiple_select(): void
    {
        $field = $this->makeField(['multiple' => true]);
        $field->fill([10, 20]);

        $value = $field->value();

        $this->assertIsArray($value);
        $this->assertSame([10, 20], $value);
    }

    // -----------------------------------------------------------------------
    // toStorage() — never has translations
    // -----------------------------------------------------------------------

    public function test_to_storage_has_no_translations(): void
    {
        $field = $this->makeField();
        $field->fill(10);

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertSame(10, $storage[0]['value']);
        $this->assertSame([], $storage[0]['translations']);
    }

    public function test_to_storage_is_not_affected_by_localizable_flag(): void
    {
        // SelectField always ignores localizable for storage — enum IDs are locale-neutral.
        $field = $this->makeField(['localizable' => true]);
        $field->fill(10);

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertSame([], $storage[0]['translations']);
    }

    // -----------------------------------------------------------------------
    // indexData()
    // -----------------------------------------------------------------------

    public function test_index_data_returns_empty_when_no_value(): void
    {
        $field = $this->makeField(['code' => 'color']);

        $this->assertSame([], $field->indexData());
    }

    public function test_index_data_includes_value_and_code_keys(): void
    {
        $field = $this->makeField(['code' => 'color']);
        $field->fill(10);

        $data = $field->indexData();

        $this->assertArrayHasKey('color', $data);
        $this->assertArrayHasKey('color_code', $data);
        $this->assertSame(10, $data['color']);
    }
}
