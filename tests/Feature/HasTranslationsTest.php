<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;
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
}
