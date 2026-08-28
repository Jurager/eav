<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Scopes\ActiveLocaleScope;

class ActiveLocaleScopeTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLocale('en');
        $this->createLocale('ru');
        $this->createLocale('fr');
    }

    public function test_every_locale_is_returned_when_none_is_active(): void
    {
        $this->assertCount(3, Locale::query()->get());
    }

    public function test_narrows_to_the_active_locale_codes(): void
    {
        app(LocaleRegistry::class)->set(['ru']);

        $this->assertSame(['ru'], Locale::query()->pluck('code')->all());
    }

    public function test_matches_any_of_several_active_codes(): void
    {
        app(LocaleRegistry::class)->set(['ru', 'en']);

        $this->assertEqualsCanonicalizing(['en', 'ru'], Locale::query()->pluck('code')->all());
    }

    public function test_applies_transparently_to_a_plain_loadMissing_with_no_closure(): void
    {
        // The whole point: a generic caller with no notion of locales — a bare relation-name
        // eager load — still gets narrowed, because the scope lives on Locale itself.
        app(LocaleRegistry::class)->set(['ru']);

        $locales = Locale::query()->with([])->get(); // stand-in for "however it got queried"

        $this->assertCount(1, $locales);
    }

    public function test_without_global_scope_bypasses_the_narrowing(): void
    {
        app(LocaleRegistry::class)->set(['ru']);

        $locales = Locale::query()->withoutGlobalScope(ActiveLocaleScope::class)->get();

        $this->assertCount(3, $locales);
    }
}
