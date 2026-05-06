<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\TextField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class TextFieldTest extends TestCase
{
    private LocaleRegistry $localeRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localeRegistry = Mockery::mock(LocaleRegistry::class);
        $this->localeRegistry->shouldReceive('has')->andReturn(true);
        $this->localeRegistry->shouldReceive('default')->andReturn(1);
        $this->localeRegistry->shouldReceive('ids')->andReturn([1]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAttribute(array $attributes = []): Attribute
    {
        return (new Attribute())->forceFill(array_merge([
            'code'        => 'name',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): TextField
    {
        return new TextField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_text(): void
    {
        $this->assertSame(Field::STORAGE_TEXT, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // fill() — basic validation
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
        $this->assertFalse($field->isFilled());
    }

    public function test_fill_accepts_valid_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('hello'));
        $this->assertFalse($field->hasErrors());
        $this->assertTrue($field->isFilled());
    }

    public function test_fill_rejects_integer_value(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(42));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_string_exceeding_255_chars(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(str_repeat('x', 256)));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_accepts_string_of_exactly_255_chars(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(str_repeat('x', 255)));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_rejects_array_for_single_field(): void
    {
        $field = $this->makeField(['multiple' => false]);

        $this->assertFalse($field->fill(['a', 'b']));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_accepts_array_for_multiple_field(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertTrue($field->fill(['hello', 'world']));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_rejects_nested_array_in_multiple_field(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertFalse($field->fill([['nested']]));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // value()
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_value_returns_filled_string(): void
    {
        $field = $this->makeField();
        $field->fill('hello world');

        $this->assertSame('hello world', $field->value());
    }

    public function test_value_returns_array_for_multiple_field(): void
    {
        $field = $this->makeField(['multiple' => true]);
        $field->fill(['foo', 'bar']);

        $this->assertSame(['foo', 'bar'], $field->value());
    }

    // -----------------------------------------------------------------------
    // set() / has() / forget()
    // -----------------------------------------------------------------------

    public function test_set_stores_and_value_retrieves(): void
    {
        $field = $this->makeField();
        $field->set('new value');

        $this->assertSame('new value', $field->value());
    }

    public function test_has_returns_false_when_empty(): void
    {
        $this->assertFalse($this->makeField()->has());
    }

    public function test_has_returns_true_after_fill(): void
    {
        $field = $this->makeField();
        $field->fill('something');

        $this->assertTrue($field->has());
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill('something');
        $field->forget();

        $this->assertNull($field->value());
        $this->assertFalse($field->has());
        $this->assertFalse($field->isFilled());
    }

    // -----------------------------------------------------------------------
    // toStorage()
    // -----------------------------------------------------------------------

    public function test_to_storage_returns_single_item_for_non_multiple(): void
    {
        $field = $this->makeField();
        $field->fill('hello');

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertSame('hello', $storage[0]['value']);
        $this->assertSame([], $storage[0]['translations']);
    }

    public function test_to_storage_returns_multiple_items_for_multiple_field(): void
    {
        $field = $this->makeField(['multiple' => true]);
        $field->fill(['a', 'b']);

        $storage = $field->toStorage();

        $this->assertCount(2, $storage);
        $this->assertSame('a', $storage[0]['value']);
        $this->assertSame('b', $storage[1]['value']);
    }

    // -----------------------------------------------------------------------
    // indexData()
    // -----------------------------------------------------------------------

    public function test_index_data_returns_empty_when_no_value(): void
    {
        $field = $this->makeField(['code' => 'name']);

        $this->assertSame([], $field->indexData());
    }

    public function test_index_data_returns_value_keyed_by_code(): void
    {
        $field = $this->makeField(['code' => 'name']);
        $field->fill('John');

        $this->assertSame(['name' => 'John'], $field->indexData());
    }

    // -----------------------------------------------------------------------
    // toMetadata()
    // -----------------------------------------------------------------------

    public function test_to_metadata_returns_expected_keys(): void
    {
        $field = $this->makeField(['code' => 'title', 'searchable' => true]);
        $metadata = $field->toMetadata();

        $this->assertSame('title', $metadata['code']);
        $this->assertFalse($metadata['localizable']);
        $this->assertFalse($metadata['multiple']);
        $this->assertFalse($metadata['mandatory']);
        $this->assertTrue($metadata['searchable']);
    }

    // -----------------------------------------------------------------------
    // errors()
    // -----------------------------------------------------------------------

    public function test_errors_are_empty_after_successful_fill(): void
    {
        $field = $this->makeField();
        $field->fill('valid');

        $this->assertSame([], $field->errors());
    }

    public function test_errors_are_populated_after_failed_fill(): void
    {
        $field = $this->makeField();
        $field->fill(str_repeat('x', 256));

        $this->assertNotEmpty($field->errors());
    }
}
