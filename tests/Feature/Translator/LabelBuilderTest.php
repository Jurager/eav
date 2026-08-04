<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Translator;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Translator;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class LabelBuilderTest extends FeatureTestCase
{
    public function test_save_persists_labels_for_the_model(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $this->createLocale('en');
        $this->createLocale('ru');

        Translator::for($attribute)
            ->label('Name', 'en')
            ->label('Название', 'ru')
            ->save();

        $labels = $attribute->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Name', $labels['en']);
        $this->assertSame('Название', $labels['ru']);
    }

    public function test_save_throws_for_unknown_locale(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);

        $this->expectException(FluentBuilderException::class);

        Translator::for($attribute)->label('Name', 'xx');
    }

    public function test_fill_queues_already_shaped_translations(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $locale = $this->createLocale('en');

        Translator::for($attribute)
            ->fill([['locale_id' => $locale->id, 'label' => 'Name']])
            ->save();

        $labels = $attribute->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Name', $labels['en']);
    }

    public function test_save_without_partial_removes_locales_not_queued(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $en = $this->createLocale('en');
        $this->createLocale('ru');

        Translator::for($attribute)->label('Name', 'en')->label('Название', 'ru')->save();
        Translator::for($attribute)->label('Renamed', 'en')->save();

        $labels = $attribute->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Renamed', $labels['en']);
        $this->assertArrayNotHasKey('ru', $labels);
    }

    public function test_save_with_partial_keeps_locales_not_queued(): void
    {
        $type = $this->createAttributeType('text');
        $attribute = $this->createAttribute($type, ['code' => 'name']);
        $this->createLocale('en');
        $this->createLocale('ru');

        Translator::for($attribute)->label('Name', 'en')->label('Название', 'ru')->save();
        Translator::for($attribute)->label('Renamed', 'en')->partial()->save();

        $labels = $attribute->fresh()->translations->pluck('pivot.label', 'code')->all();

        $this->assertSame('Renamed', $labels['en']);
        $this->assertSame('Название', $labels['ru']);
    }

    public function test_batch_persists_labels_for_every_builder(): void
    {
        $type = $this->createAttributeType('text');
        $first = $this->createAttribute($type, ['code' => 'name']);
        $second = $this->createAttribute($type, ['code' => 'price']);
        $this->createLocale('en');

        Translator::batch([
            Translator::for($first)->label('Name', 'en'),
            Translator::for($second)->label('Price', 'en'),
        ]);

        $this->assertSame('Name', $first->fresh()->translations->pluck('pivot.label', 'code')->all()['en']);
        $this->assertSame('Price', $second->fresh()->translations->pluck('pivot.label', 'code')->all()['en']);
    }
}
