<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Tests\TestCase;

class LocaleRegistryTest extends TestCase
{
    private LocaleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(LocaleRegistry::class);
        $this->registry->forget();
    }

    private function insertLocale(string $code, string $name = ''): int
    {
        return DB::table('locales')->insertGetId(['code' => $code, 'name' => $name ?: $code]);
    }

    // -----------------------------------------------------------------------
    // all()
    // -----------------------------------------------------------------------

    public function test_all_returns_empty_collection_when_no_locales(): void
    {
        $this->assertTrue($this->registry->all()->isEmpty());
    }

    public function test_all_returns_locales_keyed_by_id(): void
    {
        $id = $this->insertLocale('en', 'English');

        $this->registry->forget();
        $all = $this->registry->all();

        $this->assertTrue($all->has($id));
        $this->assertSame('en', $all->get($id));
    }

    public function test_all_is_cached_after_first_call(): void
    {
        $this->insertLocale('en', 'English');
        $this->registry->forget();

        $first = $this->registry->all();

        $this->insertLocale('fr', 'French');

        $second = $this->registry->all();

        $this->assertSame($first, $second);
        $this->assertCount(1, $second);
    }

    // -----------------------------------------------------------------------
    // has() / find() / code()
    // -----------------------------------------------------------------------

    public function test_has_returns_false_for_unknown_id(): void
    {
        $this->assertFalse($this->registry->has(999));
    }

    public function test_has_returns_true_for_existing_locale(): void
    {
        $id = $this->insertLocale('de', 'German');
        $this->registry->forget();

        $this->assertTrue($this->registry->has($id));
    }

    public function test_find_returns_id_for_existing_code(): void
    {
        $id = $this->insertLocale('fr', 'French');
        $this->registry->forget();

        $this->assertSame($id, $this->registry->find('fr'));
    }

    public function test_find_returns_null_for_missing_code(): void
    {
        $this->assertNull($this->registry->find('xx'));
    }

    public function test_code_returns_code_for_existing_id(): void
    {
        $id = $this->insertLocale('es', 'Spanish');
        $this->registry->forget();

        $this->assertSame('es', $this->registry->code($id));
    }

    public function test_code_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->registry->code(9999));
    }

    // -----------------------------------------------------------------------
    // ids()
    // -----------------------------------------------------------------------

    public function test_ids_returns_empty_array_when_no_locales(): void
    {
        $this->assertSame([], $this->registry->ids());
    }

    public function test_ids_returns_all_locale_ids(): void
    {
        $id1 = $this->insertLocale('en', 'English');
        $id2 = $this->insertLocale('fr', 'French');
        $this->registry->forget();

        $ids = $this->registry->ids();

        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
    }

    // -----------------------------------------------------------------------
    // default()
    // -----------------------------------------------------------------------

    public function test_default_resolves_from_app_locale_config(): void
    {
        $id = $this->insertLocale('en', 'English');
        $this->registry->forget();

        $this->app['config']->set('app.locale', 'en');

        $this->assertSame($id, $this->registry->default());
    }

    public function test_default_throws_when_locale_not_in_db(): void
    {
        $this->registry->forget();
        $this->app['config']->set('app.locale', 'zz');

        $this->expectException(InvalidConfigurationException::class);

        $this->registry->default();
    }

    public function test_default_is_cached_after_first_call(): void
    {
        $id = $this->insertLocale('en', 'English');
        $this->registry->forget();
        $this->app['config']->set('app.locale', 'en');

        $first = $this->registry->default();
        $second = $this->registry->default();

        $this->assertSame($first, $second);
        $this->assertSame($id, $first);
    }

    /**
     * Regression: all() must not let ActiveLocaleScope narrow its own memoized
     * cache down to whatever set() holds at the moment it first runs — otherwise
     * find()/default() for any locale outside that set breaks for the rest of the
     * request, even though the locale genuinely exists in the table.
     */
    public function test_all_is_not_narrowed_by_active_locales_set_before_first_call(): void
    {
        $enId = $this->insertLocale('en', 'English');
        $this->insertLocale('fr', 'French');
        $this->registry->forget();

        // Active locale set BEFORE all() has ever been memoized.
        $this->registry->set(['fr']);

        $this->assertSame($enId, $this->registry->find('en'));
    }

    public function test_default_resolves_for_a_locale_outside_the_active_set(): void
    {
        $enId = $this->insertLocale('en', 'English');
        $this->registry->forget();
        $this->app['config']->set('app.locale', 'en');

        // Request negotiated a locale the system doesn't have — current() falls
        // through to default(), which must still find 'en' even though 'de' (not
        // 'en') is what scoped whatever the first all() call would otherwise cache.
        $this->registry->set(['de']);

        $this->assertSame($enId, $this->registry->current());
    }

    // -----------------------------------------------------------------------
    // resolve()
    // -----------------------------------------------------------------------

    public function test_resolve_returns_id_for_known_code(): void
    {
        $id = $this->insertLocale('pt', 'Portuguese');
        $this->registry->forget();

        $this->assertSame($id, $this->registry->resolve('pt'));
    }

    public function test_resolve_falls_back_to_default_for_null(): void
    {
        $id = $this->insertLocale('en', 'English');
        $this->registry->forget();
        $this->app['config']->set('app.locale', 'en');

        $this->assertSame($id, $this->registry->resolve(null));
    }

    // -----------------------------------------------------------------------
    // active locales set() / get()
    // -----------------------------------------------------------------------

    public function test_get_returns_null_before_set(): void
    {
        $this->assertNull($this->registry->get());
    }

    public function test_set_and_get_active_locales(): void
    {
        $this->registry->set(['en', 'fr']);

        $this->assertSame(['en', 'fr'], $this->registry->get());
    }

    public function test_forget_clears_active_locales(): void
    {
        $this->registry->set(['en']);
        $this->registry->forget();

        $this->assertNull($this->registry->get());
    }

    // -----------------------------------------------------------------------
    // Cross-request caching (Octane: the registry is rebuilt, the locale list isn't)
    // -----------------------------------------------------------------------

    public function test_locales_survive_a_fresh_registry_instance_without_a_query(): void
    {
        $this->insertLocale('en', 'English');
        $this->registry->all();

        // Simulate the next Octane request: the container drops the scoped instance,
        // but the static state a fresh instance reads from must still be there.
        app()->forgetScopedInstances();
        $registry = app(LocaleRegistry::class);

        DB::enableQueryLog();
        $all = $registry->all();

        $this->assertCount(1, $all);
        // Just the staleness stamp — not the rows themselves.
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'locales')));
    }

    public function test_active_locales_do_not_leak_into_the_next_request(): void
    {
        $this->registry->set(['fr']);

        app()->forgetScopedInstances();
        $registry = app(LocaleRegistry::class);

        $this->assertNull($registry->get());
    }
}
