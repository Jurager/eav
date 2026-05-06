<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Managers\TranslationManager;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;

class TranslationManagerTest extends FeatureTestCase
{
    private TranslationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(TranslationManager::class);
    }

    // -----------------------------------------------------------------------
    // Locale CRUD
    // -----------------------------------------------------------------------

    public function test_create_locale_persists_to_db(): void
    {
        $locale = $this->manager->create(['code' => 'en', 'name' => 'English']);

        $this->assertInstanceOf(Locale::class, $locale);
        $this->assertDatabaseHas('locales', ['code' => 'en']);
    }

    public function test_create_locale_invalidates_registry_cache(): void
    {
        $registry = app(LocaleRegistry::class);
        $registry->all(); // prime cache

        $this->manager->create(['code' => 'fr', 'name' => 'French']);

        // Cache must be cleared — new locale must be visible
        $this->assertNotNull($registry->find('fr'));
    }

    public function test_update_locale_changes_name(): void
    {
        $locale = $this->manager->create(['code' => 'de', 'name' => 'Deutsch']);

        $this->manager->update($locale, ['name' => 'German']);

        $this->assertDatabaseHas('locales', ['code' => 'de', 'name' => 'German']);
    }

    public function test_update_locale_invalidates_registry_cache(): void
    {
        $locale = $this->manager->create(['code' => 'es', 'name' => 'Spanish']);
        $registry = app(LocaleRegistry::class);
        $registry->all();

        $this->manager->update($locale, ['name' => 'Español']);

        // Registry should be refreshed — find() should still work
        $this->assertNotNull($registry->find('es'));
    }

    public function test_delete_locale_removes_from_db(): void
    {
        $locale = $this->manager->create(['code' => 'pt', 'name' => 'Portuguese']);

        $this->manager->delete($locale);

        $this->assertDatabaseMissing('locales', ['code' => 'pt']);
    }

    public function test_delete_locale_invalidates_registry_cache(): void
    {
        $locale = $this->manager->create(['code' => 'it', 'name' => 'Italian']);
        $registry = app(LocaleRegistry::class);
        $registry->all();

        $this->manager->delete($locale);

        $this->assertNull($registry->find('it'));
    }

    public function test_locales_returns_all_locales(): void
    {
        $this->manager->create(['code' => 'en', 'name' => 'English']);
        $this->manager->create(['code' => 'fr', 'name' => 'French']);

        $locales = $this->manager->locales();

        $this->assertCount(2, $locales);
    }

    public function test_locales_accepts_modifier_callable(): void
    {
        $this->manager->create(['code' => 'en', 'name' => 'English']);
        $this->manager->create(['code' => 'fr', 'name' => 'French']);

        $count = $this->manager->locales(fn ($q) => $q->count());

        $this->assertSame(2, $count);
    }

    public function test_locale_finds_by_id(): void
    {
        $created = $this->manager->create(['code' => 'en', 'name' => 'English']);

        $found = $this->manager->locale($created->id);

        $this->assertSame($created->id, $found->id);
        $this->assertSame('en', $found->code);
    }

    // -----------------------------------------------------------------------
    // save() — translation upsert
    // -----------------------------------------------------------------------

    public function test_save_inserts_translation_for_model(): void
    {
        $locale = $this->manager->create(['code' => 'en', 'name' => 'English']);
        $textType = $this->createAttributeType('text');
        $attr = $this->createAttribute($textType, ['code' => 'color']);

        $this->manager->save($attr, [
            ['locale_id' => $locale->id, 'label' => 'Red'],
        ]);

        $this->assertDatabaseHas('entity_translations', [
            'entity_id' => $attr->id,
            'locale_id' => $locale->id,
            'label' => 'Red',
        ]);
    }

    public function test_save_updates_existing_translation(): void
    {
        $locale = $this->manager->create(['code' => 'en', 'name' => 'English']);
        $textType = $this->createAttributeType('text');
        $attr = $this->createAttribute($textType, ['code' => 'status']);

        $this->manager->save($attr, [['locale_id' => $locale->id, 'label' => 'Active']]);
        $this->manager->save($attr, [['locale_id' => $locale->id, 'label' => 'Enabled']]);

        $count = DB::table('entity_translations')
            ->where('entity_id', $attr->id)
            ->where('locale_id', $locale->id)
            ->count();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('entity_translations', ['label' => 'Enabled']);
    }

    public function test_save_removes_translations_for_omitted_locales(): void
    {
        $en = $this->manager->create(['code' => 'en', 'name' => 'English']);
        $fr = $this->manager->create(['code' => 'fr', 'name' => 'French']);
        $textType = $this->createAttributeType('text');
        $attr = $this->createAttribute($textType, ['code' => 'label']);

        // Save both locales
        $this->manager->save($attr, [
            ['locale_id' => $en->id, 'label' => 'Color'],
            ['locale_id' => $fr->id, 'label' => 'Couleur'],
        ]);

        // Save only English — French should be deleted
        $this->manager->save($attr, [
            ['locale_id' => $en->id, 'label' => 'Colour'],
        ]);

        $count = DB::table('entity_translations')
            ->where('entity_id', $attr->id)
            ->count();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('entity_translations', ['locale_id' => $en->id]);
        $this->assertDatabaseMissing('entity_translations', ['locale_id' => $fr->id]);
    }

    public function test_save_discards_entries_without_label(): void
    {
        $locale = $this->manager->create(['code' => 'en', 'name' => 'English']);
        $textType = $this->createAttributeType('text');
        $attr = $this->createAttribute($textType, ['code' => 'tag']);

        $this->manager->save($attr, [
            ['locale_id' => $locale->id, 'label' => null],
        ]);

        $this->assertSame(0, DB::table('entity_translations')->count());
    }

    public function test_save_stores_optional_params(): void
    {
        $locale = $this->manager->create(['code' => 'en', 'name' => 'English']);
        $textType = $this->createAttributeType('text');
        $attr = $this->createAttribute($textType, ['code' => 'field']);

        $this->manager->save($attr, [
            ['locale_id' => $locale->id, 'label' => 'Field', 'hint' => 'Enter a value', 'short_name' => 'F'],
        ]);

        $row = DB::table('entity_translations')
            ->where('entity_id', $attr->id)
            ->first();

        $this->assertNotNull($row->params);
        $params = json_decode($row->params, true);
        $this->assertSame('Enter a value', $params['hint']);
        $this->assertSame('F', $params['short_name']);
    }

    // -----------------------------------------------------------------------
    // batch() — bulk translation upsert
    // -----------------------------------------------------------------------

    public function test_batch_inserts_translations_for_multiple_models(): void
    {
        $en = $this->manager->create(['code' => 'en', 'name' => 'English']);
        $fr = $this->manager->create(['code' => 'fr', 'name' => 'French']);
        $textType = $this->createAttributeType('text');

        $attr1 = $this->createAttribute($textType, ['code' => 'field1']);
        $attr2 = $this->createAttribute($textType, ['code' => 'field2']);

        $this->manager->batch([
            [$attr1, [['locale_id' => $en->id, 'label' => 'Field One']]],
            [$attr2, [['locale_id' => $fr->id, 'label' => 'Champ Deux']]],
        ]);

        $this->assertDatabaseHas('entity_translations', ['entity_id' => $attr1->id, 'label' => 'Field One']);
        $this->assertDatabaseHas('entity_translations', ['entity_id' => $attr2->id, 'label' => 'Champ Deux']);
    }

    public function test_batch_with_empty_array_is_no_op(): void
    {
        $this->manager->batch([]);

        $this->assertSame(0, DB::table('entity_translations')->count());
    }
}
