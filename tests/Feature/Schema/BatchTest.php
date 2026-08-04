<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Schema;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Schema;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class BatchTest extends FeatureTestCase
{
    public function test_batch_persists_every_builder(): void
    {
        $this->createAttributeType('text');
        $this->createAttributeType('number');

        $created = Schema::batch([
            Schema::attribute('color', 'product')->type('text'),
            Schema::attribute('weight', 'product')->type('number'),
        ]);

        $this->assertCount(2, $created);
        $this->assertTrue($created->has('product:color'));
        $this->assertTrue($created->has('product:weight'));
    }

    public function test_batch_persists_translations(): void
    {
        $this->createAttributeType('text');
        $this->createLocale('en');

        $created = Schema::batch([
            Schema::attribute('color', 'product')->type('text')->label('Color', 'en'),
        ]);

        $attribute = $created->get('product:color');
        $labels = $attribute->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Color', $labels['en']);
    }

    public function test_batch_does_not_persist_anything_when_empty(): void
    {
        $created = Schema::batch([]);

        $this->assertCount(0, $created);
    }

    public function test_batch_throws_when_a_builder_is_missing_its_type(): void
    {
        $this->expectException(FluentBuilderException::class);

        Schema::batch([
            Schema::attribute('color', 'product'),
        ]);
    }

    public function test_batch_throws_when_a_builder_was_constructed_from_an_existing_attribute(): void
    {
        $this->createAttributeType('text');
        $attribute = Schema::attribute('color', 'product')->type('text')->create();

        $this->expectException(FluentBuilderException::class);

        Schema::batch([
            Schema::attribute($attribute)->required(),
        ]);
    }
}
