<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class AttributeBuilderTest extends FeatureTestCase
{
    public function test_create_persists_an_attribute_with_the_resolved_type(): void
    {
        $this->createAttributeType('text');

        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        $this->assertInstanceOf(Attribute::class, $attribute);
        $this->assertSame('name', $attribute->code);
        $this->assertSame('product', $attribute->entity_type);
        $this->assertSame('text', $attribute->type->code);
    }

    public function test_create_throws_for_unknown_type_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name', 'product')->type('nonexistent');
    }

    public function test_create_throws_when_type_was_never_set(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name', 'product')->create();
    }

    public function test_group_resolves_by_code(): void
    {
        $this->createAttributeType('text');
        $group = Schema::group('default')->create();

        $attribute = Schema::attribute('name', 'product')->type('text')->group('default')->create();

        $this->assertTrue($group->is($attribute->group));
    }

    public function test_create_throws_for_unknown_group_code(): void
    {
        $this->createAttributeType('text');

        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name', 'product')->type('text')->group('nonexistent');
    }

    public function test_boolean_flags_default_to_false(): void
    {
        $this->createAttributeType('text');

        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        $this->assertFalse($attribute->required);
        $this->assertFalse($attribute->unique);
        $this->assertFalse($attribute->localizable);
        $this->assertFalse($attribute->multiple);
        $this->assertFalse($attribute->filterable);
        $this->assertFalse($attribute->searchable);
    }

    public function test_boolean_flags_are_set_when_called(): void
    {
        // A type must itself support a flag, or AttributeType::constrain() forces it back to false.
        AttributeType::create(['code' => 'text', 'unique' => true, 'searchable' => true]);

        $attribute = Schema::attribute('name', 'product')
            ->type('text')
            ->required()
            ->unique()
            ->searchable()
            ->create();

        $this->assertTrue($attribute->required);
        $this->assertTrue($attribute->unique);
        $this->assertTrue($attribute->searchable);
        $this->assertFalse($attribute->localizable);
    }

    public function test_boolean_flags_accept_an_explicit_value(): void
    {
        $this->createAttributeType('text');

        $attribute = Schema::attribute('name', 'product')
            ->type('text')
            ->required(false)
            ->create();

        $this->assertFalse($attribute->required);
    }

    public function test_create_persists_translated_labels(): void
    {
        $this->createAttributeType('text');
        $this->createLocale('ru');
        $this->createLocale('en');

        $attribute = Schema::attribute('name', 'product')
            ->type('text')
            ->label('Название', 'ru')
            ->label('Name', 'en')
            ->create();

        $labels = $attribute->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Название', $labels['ru']);
        $this->assertSame('Name', $labels['en']);
    }

    public function test_set_allows_arbitrary_extra_columns(): void
    {
        $this->createAttributeType('text');

        $attribute = Schema::attribute('name', 'product')
            ->type('text')
            ->set('sort', 5)
            ->create();

        $this->assertSame(5, $attribute->sort);
    }

    public function test_constructing_with_a_code_and_no_entity_type_throws(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name');
    }

    public function test_update_persists_changes_to_an_existing_attribute(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        $updated = Schema::attribute($attribute)->required()->update();

        $this->assertTrue($updated->is($attribute));
        $this->assertTrue($updated->required);
    }

    public function test_update_does_not_require_type_to_be_set(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        $updated = Schema::attribute($attribute)->required()->update();

        $this->assertSame($attribute->attribute_type_id, $updated->attribute_type_id);
    }

    public function test_update_persists_translated_labels(): void
    {
        $this->createAttributeType('text');
        $this->createLocale('en');
        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        Schema::attribute($attribute)->label('Name', 'en')->update();

        $labels = $attribute->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Name', $labels['en']);
    }

    public function test_create_throws_when_builder_was_constructed_from_an_existing_attribute(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        $this->expectException(FluentBuilderException::class);

        Schema::attribute($attribute)->create();
    }

    public function test_update_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->createAttributeType('text');

        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name', 'product')->type('text')->update();
    }

    public function test_delete_removes_an_existing_attribute(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('name', 'product')->type('text')->create();

        Schema::attribute($attribute)->delete();

        $this->assertTrue($attribute->fresh()->trashed());
    }

    public function test_delete_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->createAttributeType('text');

        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name', 'product')->type('text')->delete();
    }

    public function test_move_to_repositions_the_attribute(): void
    {
        $this->createAttributeType('text');
        $first = Schema::attribute('name', 'product')->type('text')->create();
        $second = Schema::attribute('code', 'product')->type('text')->create();

        $moved = Schema::attribute($second)->moveTo(0);

        $this->assertSame(0, $moved->fresh()->sort);
        $this->assertSame(1, $first->fresh()->sort);
    }

    public function test_move_to_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->createAttributeType('text');

        $this->expectException(FluentBuilderException::class);

        Schema::attribute('name', 'product')->type('text')->moveTo(0);
    }

    public function test_first_or_create_creates_a_missing_attribute(): void
    {
        $this->createAttributeType('text');

        $attribute = Schema::attribute('name', 'product')->type('text')->firstOrCreate();

        $this->assertSame('name', $attribute->code);
    }

    public function test_first_or_create_returns_the_existing_attribute(): void
    {
        $this->createAttributeType('text');
        $original = Schema::attribute('name', 'product')->type('text')->required()->create();

        $found = Schema::attribute('name', 'product')->type('text')->firstOrCreate();

        $this->assertTrue($found->is($original));
        $this->assertTrue($found->required);
    }

    public function test_first_or_create_only_updates_translations_for_an_existing_attribute(): void
    {
        $this->createAttributeType('text');
        $this->createLocale('en');
        Schema::attribute('name', 'product')->type('text')->required()->create();

        $found = Schema::attribute('name', 'product')->type('text')->label('Name', 'en')->firstOrCreate();

        $this->assertTrue($found->required);
        $this->assertSame('Name', $found->translations->pluck('pivot.label', 'code')->all()['en']);
    }
}
