<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Fields;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FileField;
use Jurager\Eav\Fields\ImageField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;
use Mockery;

class FileFieldTest extends TestCase
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
            'code'        => 'document',
            'localizable' => false,
            'multiple'    => false,
            'mandatory'   => false,
            'unique'      => false,
            'filterable'  => false,
            'searchable'  => false,
            'validations' => null,
        ], $attributes));
    }

    private function makeField(array $attributes = []): FileField
    {
        return new FileField($this->makeAttribute($attributes), $this->localeRegistry);
    }

    // -----------------------------------------------------------------------
    // column()
    // -----------------------------------------------------------------------

    public function test_column_returns_value_text(): void
    {
        $this->assertSame(Field::STORAGE_TEXT, $this->makeField()->column());
    }

    // -----------------------------------------------------------------------
    // ImageField is a FileField subclass
    // -----------------------------------------------------------------------

    public function test_image_field_extends_file_field(): void
    {
        $attribute = $this->makeAttribute(['code' => 'photo']);
        $field = new ImageField($attribute, $this->localeRegistry);

        $this->assertInstanceOf(FileField::class, $field);
        $this->assertSame(Field::STORAGE_TEXT, $field->column());
    }

    // -----------------------------------------------------------------------
    // fill() — permissive validation accepts anything
    // -----------------------------------------------------------------------

    public function test_fill_accepts_null(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill(null));
        $this->assertFalse($field->hasErrors());
        $this->assertFalse($field->isFilled());
    }

    public function test_fill_accepts_file_path_string(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('uploads/document.pdf'));
        $this->assertFalse($field->hasErrors());
    }

    public function test_fill_accepts_absolute_url(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->fill('https://cdn.example.com/file.pdf'));
        $this->assertFalse($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // value()
    // -----------------------------------------------------------------------

    public function test_value_returns_null_when_empty(): void
    {
        $this->assertNull($this->makeField()->value());
    }

    public function test_value_returns_file_path(): void
    {
        $field = $this->makeField();
        $field->fill('uploads/file.pdf');

        $this->assertSame('uploads/file.pdf', $field->value());
    }

    // -----------------------------------------------------------------------
    // Multiple file field
    // -----------------------------------------------------------------------

    public function test_fill_accepts_array_of_paths_for_multiple_field(): void
    {
        $field = $this->makeField(['multiple' => true]);

        $this->assertTrue($field->fill(['a.jpg', 'b.jpg']));
        $this->assertFalse($field->hasErrors());
    }

    public function test_multiple_fill_returns_all_elements(): void
    {
        $field = $this->makeField(['multiple' => true]);
        $field->fill(['a.jpg', 'b.jpg', 'c.jpg']);

        $value = $field->value();

        $this->assertIsArray($value);
        $this->assertCount(3, $value);
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], $value);
    }

    // -----------------------------------------------------------------------
    // set() / forget()
    // -----------------------------------------------------------------------

    public function test_set_stores_path(): void
    {
        $field = $this->makeField();
        $field->set('some/path.txt');

        $this->assertSame('some/path.txt', $field->value());
    }

    public function test_forget_clears_value(): void
    {
        $field = $this->makeField();
        $field->fill('path/to/file.pdf');
        $field->forget();

        $this->assertNull($field->value());
    }

    // -----------------------------------------------------------------------
    // toStorage()
    // -----------------------------------------------------------------------

    public function test_to_storage_contains_path(): void
    {
        $field = $this->makeField();
        $field->fill('uploads/file.pdf');

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertSame('uploads/file.pdf', $storage[0]['value']);
    }
}
