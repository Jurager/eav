<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Tests\Fixtures\SoftDeletingTranslatableEntity;
use Jurager\Eav\Tests\Fixtures\TranslatableEntity;

class HasTranslationsTest extends FeatureTestCase
{
    private Locale $en;

    private Locale $fr;

    private TranslatableEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.locale', 'en');

        $this->en = $this->createLocale('en');
        $this->fr = $this->createLocale('fr');

        $this->entity = TranslatableEntity::create(['name' => 'Astana']);
        $this->entity->translations()->attach([
            $this->en->id => ['label' => 'Astana'],
            $this->fr->id => ['label' => 'Astana (FR)'],
        ]);
    }

    private function fresh(): TranslatableEntity
    {
        return TranslatableEntity::with('translations')->findOrFail($this->entity->id);
    }

    public function test_label_resolves_current_request_locale(): void
    {
        app(LocaleRegistry::class)->set(['fr']);

        $this->assertSame('Astana (FR)', $this->fresh()->label());
    }

    public function test_label_falls_back_to_default_when_no_request_locale_active(): void
    {
        app(LocaleRegistry::class)->forget();
        $this->app['config']->set('app.locale', 'en');

        $this->assertSame('Astana', $this->fresh()->label());
    }

    public function test_label_with_explicit_locale_overrides_current_request_locale(): void
    {
        app(LocaleRegistry::class)->set(['fr']);

        $this->assertSame('Astana', $this->fresh()->label($this->en->id));
    }

    public function test_label_falls_back_to_first_translation_when_active_locale_has_none(): void
    {
        $this->createLocale('de');
        app(LocaleRegistry::class)->set(['de']);

        // 'de' has no translation row for this entity — falls back to the first loaded translation.
        $this->assertSame('Astana', $this->fresh()->label());
    }

    public function test_translations_relation_exposes_both_locales(): void
    {
        $this->assertCount(2, $this->fresh()->translations);
    }

    public function test_deleting_the_model_removes_only_its_translations(): void
    {
        $other = TranslatableEntity::create(['name' => 'Almaty']);
        $other->translations()->attach([$this->en->id => ['label' => 'Almaty']]);

        $this->entity->delete();

        $this->assertDatabaseMissing('entity_translations', ['entity_type' => $this->entity->getMorphClass(), 'entity_id' => $this->entity->id]);
        $this->assertDatabaseHas('entity_translations', ['entity_type' => $other->getMorphClass(), 'entity_id' => $other->id]);
        $this->assertDatabaseCount('locales', 2);
    }

    public function test_a_soft_deleted_model_keeps_its_translations_until_force_deleted(): void
    {
        Schema::create('soft_deleting_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        $entity = SoftDeletingTranslatableEntity::create(['name' => 'Shymkent']);
        $entity->translations()->attach([$this->en->id => ['label' => 'Shymkent']]);
        $key = ['entity_type' => $entity->getMorphClass(), 'entity_id' => $entity->id];

        $entity->delete();
        $this->assertDatabaseHas('entity_translations', $key);

        $entity->forceDelete();
        $this->assertDatabaseMissing('entity_translations', $key);

        Schema::drop('soft_deleting_entities');
    }
}
