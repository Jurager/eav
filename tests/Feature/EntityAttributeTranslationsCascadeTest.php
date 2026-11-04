<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Attributes\Persistence\AttributePersister;
use Jurager\Eav\Fields\Text;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

class EntityAttributeTranslationsCascadeTest extends FeatureTestCase
{
    private Attribute $attr;

    private int $enLocaleId;

    private int $ruLocaleId;

    private EntityAttribute $row;

    protected function setUp(): void
    {
        parent::setUp();

        $en = $this->createLocale('en');
        $ru = $this->createLocale('ru');

        $this->enLocaleId = $en->id;
        $this->ruLocaleId = $ru->id;

        $textType = $this->createAttributeType('text');

        $this->attr = $this->createAttribute($textType, [
            'code' => 'label',
            'localizable' => true,
            'multiple' => false,
        ]);

        $product = $this->createProduct();
        $persister = app(AttributePersister::class, ['entity' => $product]);
        $field = new Text($this->attr, app(LocaleRegistry::class), app(EnumRegistry::class));

        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->ruLocaleId, 'values' => 'Цвет'],
        ]);

        $persister->persist(collect([$field]));

        $this->row = EntityAttribute::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->firstOrFail();
    }

    public function test_translations_include_every_locale(): void
    {
        $this->assertCount(2, $this->row->translations);
    }

    /**
     * Deleting a value must remove translation pivot rows while preserving locales.
     */
    public function test_deleting_the_row_removes_its_translations_without_touching_locales(): void
    {
        $localesBefore = DB::table('locales')->count();

        $this->row->delete();

        $this->assertSame(0, DB::table('entity_translations')
            ->where('entity_type', 'entity_attribute')
            ->where('entity_id', $this->row->id)
            ->count());

        $this->assertSame($localesBefore, DB::table('locales')->count());
    }

    /**
     * Deleting a value must remove translations for inactive locales too.
     */
    public function test_deleting_the_row_removes_every_locale_even_while_active_locale_scope_narrows(): void
    {
        app(LocaleRegistry::class)->set(['ru']);

        $this->row->delete();

        $this->assertSame(0, DB::table('entity_translations')
            ->where('entity_type', 'entity_attribute')
            ->where('entity_id', $this->row->id)
            ->count());
    }
}
