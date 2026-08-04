<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class GroupBuilderTest extends FeatureTestCase
{
    public function test_create_persists_a_group_with_code(): void
    {
        $group = Schema::group('default')->create();

        $this->assertInstanceOf(AttributeGroup::class, $group);
        $this->assertSame('default', $group->code);
    }

    public function test_create_persists_translated_labels(): void
    {
        $this->createLocale('ru');
        $this->createLocale('en');

        $group = Schema::group('default')
            ->label('Атрибуты', 'ru')
            ->label('Attributes', 'en')
            ->create();

        $labels = $group->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Атрибуты', $labels['ru']);
        $this->assertSame('Attributes', $labels['en']);
    }

    public function test_create_throws_for_unknown_locale(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::group('default')->label('Attributes', 'xx');
    }

    public function test_sort_is_auto_incremented_when_omitted(): void
    {
        $first = Schema::group('first')->create();
        $second = Schema::group('second')->create();

        $this->assertTrue($second->sort > $first->sort);
    }

    public function test_explicit_sort_is_respected(): void
    {
        $group = Schema::group('default')->sort(42)->create();

        $this->assertSame(42, $group->sort);
    }

    public function test_update_persists_changes_to_an_existing_group(): void
    {
        $group = Schema::group('default')->create();

        $updated = Schema::group($group)->sort(9)->update();

        $this->assertTrue($updated->is($group));
        $this->assertSame(9, $updated->sort);
    }

    public function test_update_persists_translated_labels(): void
    {
        $this->createLocale('en');
        $group = Schema::group('default')->create();

        Schema::group($group)->label('Attributes', 'en')->update();

        $labels = $group->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Attributes', $labels['en']);
    }

    public function test_create_throws_when_builder_was_constructed_from_an_existing_group(): void
    {
        $group = Schema::group('default')->create();

        $this->expectException(FluentBuilderException::class);

        Schema::group($group)->create();
    }

    public function test_update_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::group('default')->update();
    }

    public function test_delete_removes_an_existing_group(): void
    {
        $group = Schema::group('default')->create();

        Schema::group($group)->delete();

        $this->assertNull(Schema::groups()->find($group->id));
    }

    public function test_delete_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::group('default')->delete();
    }

    public function test_move_to_repositions_the_group(): void
    {
        $first = Schema::group('first')->create();
        $second = Schema::group('second')->create();

        $moved = Schema::group($second)->moveTo(0);

        $this->assertSame(0, $moved->fresh()->sort);
        $this->assertSame(1, $first->fresh()->sort);
    }

    public function test_move_to_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::group('default')->moveTo(0);
    }

    public function test_attach_assigns_attributes_to_the_group(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'color']);
        $group = Schema::group('default')->create();

        Schema::group($group)->attach([$attribute->id]);

        $this->assertSame($group->id, $attribute->fresh()->attribute_group_id);
    }

    public function test_attach_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::group('default')->attach([1]);
    }
}
