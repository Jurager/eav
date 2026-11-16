<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Tests\Fixtures\TranslationsRequest;

class HasTranslationRulesTest extends FeatureTestCase
{
    private Locale $en;

    private Locale $fr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->en = $this->createLocale('en');
        $this->fr = $this->createLocale('fr');

        Route::post('/translations', fn (TranslationsRequest $request) => response()->noContent());
    }

    public function test_translations_with_one_label_pass(): void
    {
        $this->postJson('/translations', ['translations' => [
            ['locale_id' => $this->en->id, 'label' => 'Color'],
            ['locale_id' => $this->fr->id, 'label' => null],
        ]])->assertNoContent();
    }

    public function test_translations_are_required(): void
    {
        $this->postJson('/translations', [])->assertJsonValidationErrors('translations');
    }

    public function test_at_least_one_label_is_required(): void
    {
        $this->postJson('/translations', ['translations' => [['locale_id' => $this->en->id, 'label' => '']]])
            ->assertJsonValidationErrors(['translations' => 'At least one translation must have a non-empty label.']);
    }

    public function test_a_locale_must_exist(): void
    {
        $this->postJson('/translations', ['translations' => [['locale_id' => 999, 'label' => 'Color']]])
            ->assertJsonValidationErrors('translations.0.locale_id');
    }

    public function test_a_locale_cannot_repeat(): void
    {
        $this->postJson('/translations', ['translations' => [
            ['locale_id' => $this->en->id, 'label' => 'Color'],
            ['locale_id' => $this->en->id, 'label' => 'Colour'],
        ]])->assertJsonValidationErrors(['translations' => 'Translations contain duplicate locale_id values.']);
    }

    public function test_extra_rules_apply_to_each_translation(): void
    {
        $this->postJson('/translations', ['translations' => [['locale_id' => $this->en->id, 'label' => 'Color', 'short_name' => 'Colorfulness']]])
            ->assertJsonValidationErrors('translations.0.short_name');
    }

    public function test_messages_can_be_translated_by_the_application(): void
    {
        $this->app['translator']->addLines(['translations.validation.label_required' => 'Укажите название.'], 'ru', 'eav');
        $this->app->setLocale('ru');

        $this->postJson('/translations', ['translations' => [['locale_id' => $this->en->id, 'label' => '']]])
            ->assertJsonValidationErrors(['translations' => 'Укажите название.']);
    }
}
