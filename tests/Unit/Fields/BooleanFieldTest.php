<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\Boolean;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registries\EnumRegistry;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanFieldTest extends TestCase
{
    private LocaleRegistry $localeRegistry;

    private EnumRegistry $enumRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localeRegistry = Mockery::mock(LocaleRegistry::class);
        $this->localeRegistry->shouldReceive('has')->andReturn(true);
        $this->localeRegistry->shouldReceive('default')->andReturn(1);

        $this->enumRegistry = Mockery::mock(EnumRegistry::class);
    }

    private function makeAttribute(array $attributes = []): Attribute
    {
        return (new Attribute)->forceFill(array_merge([
            'code' => 'active',
            'localizable' => false,
            'multiple' => false,
            'required' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): Boolean
    {
        return new Boolean($this->makeAttribute($attributes), $this->localeRegistry, $this->enumRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_boolean(): void
    {
        $this->assertSame(AttributeStorage::Boolean, $this->makeField()->column());
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
    // toIndex()
    // -----------------------------------------------------------------------

    public function test_to_index_is_null_without_a_stored_value(): void
    {
        $this->assertNull($this->makeField(['code' => 'active'])->toIndex());
    }

    public function test_to_index_is_a_boolean_so_false_can_be_filtered_on(): void
    {
        $field = $this->makeField(['code' => 'active']);

        $field->fill(false);
        $this->assertFalse($field->toIndex());

        $field->fill(true);
        $this->assertTrue($field->toIndex());
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
