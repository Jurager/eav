<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\Select;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\EntityTranslation;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registries\EnumRegistry;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class SelectFieldTest extends TestCase
{
    private LocaleRegistry $localeRegistry;

    private EnumRegistry $enumRegistry;

    /** Valid enum IDs for the test attribute (id=1). */
    private array $validEnumIds = [10 => true, 20 => true, 30 => true];

    /** Enum IDs of the test attribute by option code. */
    private array $enumIdsByCode = ['blue' => 20];

    protected function setUp(): void
    {
        parent::setUp();

        $this->localeRegistry = Mockery::mock(LocaleRegistry::class);
        $this->localeRegistry->shouldReceive('has')->andReturn(true);
        $this->localeRegistry->shouldReceive('default')->andReturn(1);
        $this->localeRegistry->shouldReceive('ids')->andReturn([1]);
        $this->localeRegistry->shouldReceive('code')->andReturn('en');

        $this->enumRegistry = Mockery::mock(EnumRegistry::class);
        $this->enumRegistry->shouldReceive('isValidId')
            ->andReturnUsing(fn ($attrId, $id) => isset($this->validEnumIds[$id]));
        $this->enumRegistry->shouldReceive('coerce')
            ->andReturnUsing(fn ($attrId, $value) => is_numeric($value) ? $value : (is_string($value) ? ($this->enumIdsByCode[$value] ?? null) : null));
        $this->enumRegistry->shouldReceive('find')
            ->byDefault()
            ->andReturnNull();
    }

    private function makeAttribute(array $attributes = []): Attribute
    {
        $attr = (new Attribute)->forceFill(array_merge([
            'id' => 1,
            'code' => 'color',
            'localizable' => false,
            'multiple' => false,
            'required' => false,
            'unique' => false,
            'filterable' => false,
            'searchable' => false,
            'validations' => null,
        ], $attributes));

        $attr->setRelation('enums', collect());

        return $attr;
    }

    private function makeField(array $attributes = []): Select
    {
        return new Select($this->makeAttribute($attributes), $this->localeRegistry, $this->enumRegistry);
    }

    private function translation(int $localeId, string $label): Locale
    {
        $locale = (new Locale)->forceFill(['id' => $localeId]);
        $locale->setRelation('pivot', (new EntityTranslation)->forceFill(['locale_id' => $localeId, 'label' => $label]));

        return $locale;
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_integer(): void
    {
        $this->assertSame(AttributeStorage::Integer, $this->makeField()->column());
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

    public function test_fill_accepts_an_option_code(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('blue'));
        $this->assertSame(20, $field->value());
    }

    public function test_set_stores_the_option_id_of_a_code(): void
    {
        $field = $this->makeField();

        $field->set('blue');

        $this->assertSame(20, $field->value());
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
    // label()
    // -----------------------------------------------------------------------

    public function test_label_reads_the_current_locale_when_none_is_given(): void
    {
        $this->localeRegistry->shouldReceive('current')->andReturn(2);

        $enum = (new AttributeEnum)->forceFill(['id' => 10, 'code' => 'red', 'attribute_id' => 1]);
        $enum->setRelation('translations', collect([$this->translation(1, 'Red'), $this->translation(2, 'Красный')]));

        $this->enumRegistry->shouldReceive('find')->with(1, 10)->andReturn($enum);

        $field = $this->makeField();
        $field->fill(10);

        $this->assertSame('Красный', $field->label());
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
        // Select always ignores localizable for storage — enum IDs are locale-neutral.
        $field = $this->makeField(['localizable' => true]);
        $field->fill(10);

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertSame([], $storage[0]['translations']);
    }

    // -----------------------------------------------------------------------
    // toIndex()
    // -----------------------------------------------------------------------

    public function test_to_index_is_null_when_no_value(): void
    {
        $this->assertNull($this->makeField(['code' => 'color'])->toIndex());
    }

    public function test_to_index_is_the_option_code(): void
    {
        $enum = (new AttributeEnum)->forceFill([
            'id' => 10, 'code' => 'red', 'attribute_id' => 1,
        ]);
        $enum->setRelation('translations', collect());

        $this->enumRegistry->shouldReceive('find')
            ->with(1, 10)
            ->andReturn($enum);

        $field = $this->makeField(['code' => 'color']);
        $field->fill(10);

        $this->assertSame('red', $field->toIndex());
    }

    // -----------------------------------------------------------------------
    // indexFields()
    // -----------------------------------------------------------------------

    public function test_index_fields_are_the_option_code_and_its_localized_labels(): void
    {
        $fields = $this->makeField(['code' => 'color', 'filterable' => true, 'searchable' => true])->indexFields();

        $this->assertSame(['attributes.color', 'labels.color'], array_column($fields, 'path'));
        $this->assertTrue($fields[0]->filterable);
        $this->assertFalse($fields[0]->searchable);
        $this->assertTrue($fields[1]->searchable);
        $this->assertTrue($fields[1]->localized);
    }
}
