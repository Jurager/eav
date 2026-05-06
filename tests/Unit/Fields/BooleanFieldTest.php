<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\BooleanField;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanFieldTest extends TestCase
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
            'code'        => 'active',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): BooleanField
    {
        return new BooleanField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_boolean(): void
    {
        $this->assertSame(Field::STORAGE_BOOLEAN, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // fill() — accepted inputs
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
        $this->assertFalse($field->isFilled());
    }

    public function test_fill_accepts_true(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(true));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_false(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(false));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_integer_one(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(1));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_integer_zero(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(0));
        $this->assertFalse($field->hasErrors());
    }

    #[DataProvider('truthy_string_provider')]
    public function test_fill_accepts_truthy_strings(string $input): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill($input));
        $this->assertFalse($field->hasErrors());
    }

    public static function truthy_string_provider(): array
    {
        return [
            ['true'], ['false'], ['yes'], ['no'], ['on'], ['off'], ['1'], ['0'],
            ['TRUE'], ['FALSE'], ['YES'], ['NO'],
        ];
    }

    public function test_fill_rejects_arbitrary_string(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill('maybe'));
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_arbitrary_integer(): void
    {
        $field = $this->makeField();

        $this->assertFalse($field->fill(2));
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // value() — typed bool cast
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_value_returns_true_for_truthy_input(): void
    {
        $field = $this->makeField();
        $field->fill('yes');

        $this->assertTrue($field->value());
    }

    public function test_value_returns_false_for_falsy_input(): void
    {
        $field = $this->makeField();
        $field->fill('no');

        $this->assertFalse($field->value());
    }

    public function test_value_returns_true_for_bool_true(): void
    {
        $field = $this->makeField();
        $field->fill(true);

        $this->assertTrue($field->value());
    }

    public function test_value_returns_false_for_bool_false(): void
    {
        $field = $this->makeField();
        $field->fill(false);

        $this->assertFalse($field->value());
    }

    public function test_value_is_typed_bool(): void
    {
        $field = $this->makeField();
        $field->fill(true);

        $this->assertIsBool($field->value());
    }

    // -----------------------------------------------------------------------
    // indexData()
    // -----------------------------------------------------------------------

    public function test_index_data_returns_false_when_no_value_set(): void
    {
        $field = $this->makeField(['code' => 'active']);

        $this->assertSame(['active' => false], $field->indexData());
    }

    public function test_index_data_returns_true_when_filled_with_true(): void
    {
        $field = $this->makeField(['code' => 'active']);
        $field->fill(true);

        $this->assertSame(['active' => true], $field->indexData());
    }

    // -----------------------------------------------------------------------
    // set() / forget()
    // -----------------------------------------------------------------------

    public function test_set_and_value_roundtrip(): void
    {
        $field = $this->makeField();
        $field->set(true);

        $this->assertTrue($field->value());
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill(true);
        $field->forget();

        $this->assertNull($field->value());
        $this->assertFalse($field->isFilled());
    }
}
