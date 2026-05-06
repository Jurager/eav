<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Fields\TextField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\AttributePersister;

class LocalizableFieldTest extends FeatureTestCase
{
    private Attribute $attr;

    private int $enLocaleId;

    private int $frLocaleId;

    protected function setUp(): void
    {
        parent::setUp();

        $en = $this->createLocale('en');
        $fr = $this->createLocale('fr');

        $this->enLocaleId = $en->id;
        $this->frLocaleId = $fr->id;

        $textType = $this->createAttributeType('text');

        $this->attr = $this->createAttribute($textType, [
            'code'        => 'label',
            'localizable' => true,
            'multiple'    => false,
        ]);
    }

    private function makeField(bool $multiple = false): TextField
    {
        $attr = $multiple
            ? $this->createAttribute(
                $this->createAttributeType('text2'),
                ['code' => 'tags', 'localizable' => true, 'multiple' => true]
            )
            : $this->attr;

        $registry = app(LocaleRegistry::class);

        return new TextField($attr, $registry);
    }

    // -----------------------------------------------------------------------
    // fill() with localizable payload
    // -----------------------------------------------------------------------

    public function test_fill_accepts_localizable_payload(): void
    {
        $field = $this->makeField();

        $result = $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);

        $this->assertTrue($result);
        $this->assertFalse($field->hasErrors());
    }

    public function test_value_returns_translation_for_locale(): void
    {
        $field = $this->makeField();

        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);

        $this->assertSame('Color', $field->value($this->enLocaleId));
        $this->assertSame('Couleur', $field->value($this->frLocaleId));
    }

    public function test_fill_rejects_non_array_for_localizable_field(): void
    {
        $field = $this->makeField();

        $result = $field->fill('not an array');

        $this->assertFalse($result);
        $this->assertTrue($field->hasErrors());
    }

    public function test_fill_rejects_invalid_locale_id(): void
    {
        $field = $this->makeField();

        $result = $field->fill([
            ['locale_id' => 99999, 'values' => 'Unknown locale'],
        ]);

        $this->assertFalse($result);
        $this->assertTrue($field->hasErrors());
    }

    // -----------------------------------------------------------------------
    // toStorage() — localizable field produces translations, not value_text
    // -----------------------------------------------------------------------

    public function test_to_storage_has_null_value_and_translations_for_localizable(): void
    {
        $field = $this->makeField();

        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);

        $storage = $field->toStorage();

        $this->assertCount(1, $storage);
        $this->assertNull($storage[0]['value']);
        $this->assertCount(2, $storage[0]['translations']);
    }

    // -----------------------------------------------------------------------
    // Persisting localizable values
    // -----------------------------------------------------------------------

    public function test_persister_saves_localizable_field_to_translations_table(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);
        $registry  = app(LocaleRegistry::class);
        $field     = new TextField($this->attr, $registry);

        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);

        $persister->persist(collect([$field]));

        // entity_attribute row should have null value_text
        $row = DB::table('entity_attribute')
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('attribute_id', $this->attr->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->value_text);

        // Translations should be in entity_translations
        $translations = DB::table('entity_translations')
            ->where('entity_type', 'entity_attribute')
            ->where('entity_id', $row->id)
            ->orderBy('locale_id')
            ->get();

        $this->assertCount(2, $translations);
    }

    public function test_persister_saves_correct_translation_labels(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);
        $registry  = app(LocaleRegistry::class);
        $field     = new TextField($this->attr, $registry);

        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);

        $persister->persist(collect([$field]));

        $eaRow = DB::table('entity_attribute')->where('entity_type', 'product')->first();

        $enRow = DB::table('entity_translations')
            ->where('entity_id', $eaRow->id)
            ->where('locale_id', $this->enLocaleId)
            ->first();

        $frRow = DB::table('entity_translations')
            ->where('entity_id', $eaRow->id)
            ->where('locale_id', $this->frLocaleId)
            ->first();

        $this->assertSame('Color', $enRow->label);
        $this->assertSame('Couleur', $frRow->label);
    }

    public function test_updating_localizable_field_prunes_removed_locale(): void
    {
        $product   = $this->createProduct();
        $persister = new AttributePersister($product);
        $registry  = app(LocaleRegistry::class);
        $field     = new TextField($this->attr, $registry);

        // Persist both EN and FR
        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);
        $persister->persist(collect([$field]));

        // Update with only EN — FR should be removed
        $field2 = new TextField($this->attr, $registry);
        $field2->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Colour'],
        ]);
        $persister->persist(collect([$field2]));

        $eaRow = DB::table('entity_attribute')->where('entity_type', 'product')->first();

        $count = DB::table('entity_translations')
            ->where('entity_id', $eaRow->id)
            ->count();

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('entity_translations', ['locale_id' => $this->frLocaleId]);
    }

    // -----------------------------------------------------------------------
    // indexData() for localizable fields
    // -----------------------------------------------------------------------

    public function test_index_data_returns_all_locale_values(): void
    {
        $field = $this->makeField();

        $field->fill([
            ['locale_id' => $this->enLocaleId, 'values' => 'Color'],
            ['locale_id' => $this->frLocaleId, 'values' => 'Couleur'],
        ]);

        $data = $field->indexData();

        $this->assertArrayHasKey('label', $data);
        $this->assertIsArray($data['label']);
        $this->assertContains('Color', $data['label']);
        $this->assertContains('Couleur', $data['label']);
    }
}
