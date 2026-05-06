<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\TextAreaField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class TextAreaFieldTest extends TestCase
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
        return (new Attribute())->forceFill(array_merge([
            'code'        => 'description',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): TextAreaField
    {
        return new TextAreaField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    public function test_column_returns_value_text(): void
    {
        $this->assertSame(Field::STORAGE_TEXT, $this->makeField()->column());
    }

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_short_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('hello'));
        $this->assertSame('hello', $field->value());
    }

    public function test_fill_accepts_long_string_beyond_255_chars(): void
    {
        $field = $this->makeField();
        $long = str_repeat('x', 5000);

        $this->assertTrue($field->fill($long));
        $this->assertSame($long, $field->value());
    }

    public function test_fill_rejects_non_string(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(42));
        $this->assertTrue($field->hasErrors());
    }

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_set_and_value(): void
    {
        $field = $this->makeField();
        $field->set('long content here');

        $this->assertSame('long content here', $field->value());
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill('some text');
        $field->forget();

        $this->assertNull($field->value());
    }
}
