<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Translator;

use Jurager\Eav\Exceptions\FluentBuilderException;
use Jurager\Eav\Facades\Translator;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Tests\Feature\FeatureTestCase;

class LocaleBuilderTest extends FeatureTestCase
{
    public function test_create_persists_a_locale_with_code(): void
    {
        $locale = Translator::locale('de')->name('German')->create();

        $this->assertInstanceOf(Locale::class, $locale);
        $this->assertSame('de', $locale->code);
        $this->assertSame('German', $locale->name);
    }

    public function test_update_persists_changes_to_an_existing_locale(): void
    {
        $locale = Translator::locale('de')->name('German')->create();

        $updated = Translator::locale($locale)->name('Deutsch')->update();

        $this->assertTrue($updated->is($locale));
        $this->assertSame('Deutsch', $updated->name);
    }

    public function test_delete_removes_an_existing_locale(): void
    {
        $locale = Translator::locale('de')->name('German')->create();

        Translator::locale($locale)->delete();

        $this->assertNull(Locale::find($locale->id));
    }

    public function test_create_throws_when_builder_was_constructed_from_an_existing_locale(): void
    {
        $locale = Translator::locale('de')->name('German')->create();

        $this->expectException(FluentBuilderException::class);

        Translator::locale($locale)->create();
    }

    public function test_update_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Translator::locale('de')->update();
    }

    public function test_delete_throws_when_builder_was_constructed_from_a_code(): void
    {
        $this->expectException(FluentBuilderException::class);

        Translator::locale('de')->delete();
    }

    public function test_find_locale_returns_the_matching_locale(): void
    {
        $locale = Translator::locale('de')->name('German')->create();

        $this->assertTrue(Translator::findLocale($locale->id)->is($locale));
    }

    public function test_locales_returns_a_collection(): void
    {
        Translator::locale('de')->name('German')->create();
        Translator::locale('fr')->name('French')->create();

        $this->assertCount(2, Translator::locales());
    }

    public function test_locales_accepts_a_modifier(): void
    {
        Translator::locale('de')->name('German')->create();
        Translator::locale('fr')->name('French')->create();

        $result = Translator::locales(fn ($q) => $q->where('code', 'de')->get());

        $this->assertCount(1, $result);
    }
}
