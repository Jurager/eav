<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Carbon\Carbon;
use Jurager\Eav\Fields\DateField;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class DateFieldTest extends TestCase
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
            'code'        => 'released_at',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): DateField
    {
        return new DateField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_datetime(): void
    {
        $this->assertSame(Field::STORAGE_DATETIME, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // fill() — validation
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
        $this->assertFalse($field->isFilled());
    }

    public function test_fill_accepts_date_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('2024-01-15'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_datetime_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('2024-01-15 10:30:00'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_carbon_instance(): void
    {
        $field = $this->makeField();
        $carbon = Carbon::parse('2024-06-01');

        $this->assertTrue($field->fill($carbon));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_rejects_non_string_non_carbon(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(12345));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // value() — Carbon cast
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_value_returns_carbon_instance(): void
    {
        $field = $this->makeField();
        $field->fill('2024-01-15');

        $this->assertInstanceOf(Carbon::class, $field->value());
    }

    public function test_value_carbon_has_correct_date(): void
    {
        $field = $this->makeField();
        $field->fill('2024-03-20');

        $carbon = $field->value();

        $this->assertSame(2024, $carbon->year);
        $this->assertSame(3, $carbon->month);
        $this->assertSame(20, $carbon->day);
    }

    // -----------------------------------------------------------------------
    // format()
    // -----------------------------------------------------------------------

    public function test_format_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->format());
    }

    public function test_format_returns_formatted_date_string(): void
    {
        $field = $this->makeField();
        $field->fill('2024-01-15');

        $this->assertSame('2024-01-15', $field->format('Y-m-d'));
    }

    public function test_format_with_custom_format(): void
    {
        $field = $this->makeField();
        $field->fill('2024-01-15');

        $this->assertSame('15/01/2024', $field->format('d/m/Y'));
    }

    // -----------------------------------------------------------------------
    // set() / forget()
    // -----------------------------------------------------------------------

    public function test_set_and_value_roundtrip(): void
    {
        $field = $this->makeField();
        $carbon = Carbon::parse('2025-06-15');
        $field->set($carbon->toDateTimeString());

        $this->assertInstanceOf(Carbon::class, $field->value());
        $this->assertSame(2025, $field->value()->year);
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill('2024-01-15');
        $field->forget();

        $this->assertNull($field->value());
        $this->assertFalse($field->isFilled());
    }

    // -----------------------------------------------------------------------
    // indexData()
    // -----------------------------------------------------------------------

    public function test_index_data_returns_empty_when_no_value(): void
    {
        $field = $this->makeField(['code' => 'released_at']);

        $this->assertSame([], $field->indexData());
    }

    public function test_index_data_returns_unix_timestamp(): void
    {
        $field = $this->makeField(['code' => 'released_at']);
        $field->fill('2024-01-15 00:00:00');

        $data = $field->indexData();

        $this->assertArrayHasKey('released_at', $data);
        $this->assertIsInt($data['released_at']);
        $this->assertSame(Carbon::parse('2024-01-15')->timestamp, $data['released_at']);
    }

    // -----------------------------------------------------------------------
    // multiple values
    // -----------------------------------------------------------------------

    public function test_fill_accepts_multiple_dates(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertTrue($field->fill(['2024-01-01', '2024-06-01']));
        $this->assertFalse($field->hasErrors());
    }

    public function test_value_returns_array_of_carbon_for_multiple(): void
    {
        $field = $this->makeField(['multiple' => true]);
        $field->fill(['2024-01-01', '2024-06-01']);

        $value = $field->value();

        $this->assertIsArray($value);
        $this->assertCount(2, $value);
        $this->assertInstanceOf(Carbon::class, $value[0]);
        $this->assertInstanceOf(Carbon::class, $value[1]);
    }
}
