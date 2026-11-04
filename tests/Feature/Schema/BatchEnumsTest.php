<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class BatchEnumsTest extends FeatureTestCase
{
    public function test_batch_enums_persists_every_builder(): void
    {
        $type = $this->createAttributeType('select');
        $attribute = $this->createAttribute($type, ['code' => 'color']);

        $created = Schema::batch([
            Schema::enum($attribute, 'red')->sort(0),
            Schema::enum($attribute, 'blue')->sort(1),
        ]);

        $this->assertCount(2, $created);
        $this->assertTrue($created->has("{$attribute->id}:red"));
        $this->assertTrue($created->has("{$attribute->id}:blue"));
    }

    public function test_batch_enums_persists_translations(): void
    {
        $this->createLocale('en');
        $type = $this->createAttributeType('select');
        $attribute = $this->createAttribute($type, ['code' => 'color']);

        $created = Schema::batch([
            Schema::enum($attribute, 'red')->label('Red', 'en'),
        ]);

        $labels = $created->get("{$attribute->id}:red")->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Red', $labels['en']);
    }

    public function test_batch_enums_updates_an_existing_option_in_place(): void
    {
        $type = $this->createAttributeType('select');
        $attribute = $this->createAttribute($type, ['code' => 'color']);
        $existing = $this->createEnum($attribute, 'red');

        $created = Schema::batch([
            Schema::enum($attribute, 'red')->sort(3),
        ]);

        $this->assertCount(1, $created);
        $this->assertSame($existing->id, $created->get("{$attribute->id}:red")->id);
    }

    public function test_batch_enums_does_not_persist_anything_when_empty(): void
    {
        $created = Schema::batch([]);

        $this->assertCount(0, $created);
    }

    public function test_batch_enums_throws_when_a_builder_was_constructed_from_an_existing_enum(): void
    {
        $type = $this->createAttributeType('select');
        $attribute = $this->createAttribute($type, ['code' => 'color']);
        $enum = $this->createEnum($attribute, 'red');

        $this->expectException(FluentBuilderException::class);

        Schema::batch([
            Schema::enum($enum)->sort(1),
        ]);
    }
}
