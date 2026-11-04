<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class EnumBuilderTest extends FeatureTestCase
{
    private function createSelectAttribute(): Attribute
    {
        $type = $this->createAttributeType('select');

        return $this->createAttribute($type, ['code' => 'color']);
    }

    public function test_create_persists_an_enum_option_for_the_attribute(): void
    {
        $attribute = $this->createSelectAttribute();

        $enum = Schema::enum($attribute, 'red')->create();

        $this->assertInstanceOf(AttributeEnum::class, $enum);
        $this->assertSame('red', $enum->code);
        $this->assertSame($attribute->id, $enum->attribute_id);
    }

    public function test_create_persists_translated_labels(): void
    {
        $attribute = $this->createSelectAttribute();
        $this->createLocale('ru');
        $this->createLocale('en');

        $enum = Schema::enum($attribute, 'red')
            ->label('Красный', 'ru')
            ->label('Red', 'en')
            ->create();

        $labels = $enum->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Красный', $labels['ru']);
        $this->assertSame('Red', $labels['en']);
    }

    public function test_create_throws_for_unknown_locale(): void
    {
        $attribute = $this->createSelectAttribute();

        $this->expectException(FluentBuilderException::class);

        Schema::enum($attribute, 'red')->label('Red', 'xx');
    }

    public function test_sort_is_set_via_magic_call(): void
    {
        $attribute = $this->createSelectAttribute();

        $enum = Schema::enum($attribute, 'red')->sort(3)->create();

        $this->assertSame(3, $enum->sort);
    }

    public function test_two_options_can_be_created_for_the_same_attribute(): void
    {
        $attribute = $this->createSelectAttribute();

        $red = Schema::enum($attribute, 'red')->create();
        $blue = Schema::enum($attribute, 'blue')->create();

        $this->assertCount(2, $attribute->enums()->get());
        $this->assertNotSame($red->id, $blue->id);
    }

    public function test_constructing_with_an_attribute_and_no_code_throws(): void
    {
        $attribute = $this->createSelectAttribute();

        $this->expectException(FluentBuilderException::class);

        Schema::enum($attribute);
    }

    public function test_update_persists_changes_to_an_existing_enum(): void
    {
        $attribute = $this->createSelectAttribute();
        $enum = Schema::enum($attribute, 'red')->create();

        $updated = Schema::enum($enum)->sort(2)->update();

        $this->assertTrue($updated->is($enum));
        $this->assertSame(2, $updated->sort);
    }

    public function test_update_persists_translated_labels(): void
    {
        $attribute = $this->createSelectAttribute();
        $this->createLocale('en');
        $enum = Schema::enum($attribute, 'red')->create();

        Schema::enum($enum)->label('Red', 'en')->update();

        $labels = $enum->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Red', $labels['en']);
    }

    public function test_create_throws_when_builder_was_constructed_from_an_existing_enum(): void
    {
        $attribute = $this->createSelectAttribute();
        $enum = Schema::enum($attribute, 'red')->create();

        $this->expectException(FluentBuilderException::class);

        Schema::enum($enum)->create();
    }

    public function test_update_throws_when_builder_was_constructed_from_an_attribute(): void
    {
        $attribute = $this->createSelectAttribute();

        $this->expectException(FluentBuilderException::class);

        Schema::enum($attribute, 'red')->update();
    }

    public function test_delete_removes_an_existing_enum(): void
    {
        $attribute = $this->createSelectAttribute();
        $enum = Schema::enum($attribute, 'red')->create();

        Schema::enum($enum)->delete();

        $this->assertNull(Schema::findAttribute($attribute->id)->enums()->find($enum->id));
    }

    public function test_delete_throws_when_builder_was_constructed_from_an_attribute(): void
    {
        $attribute = $this->createSelectAttribute();

        $this->expectException(FluentBuilderException::class);

        Schema::enum($attribute, 'red')->delete();
    }
}
