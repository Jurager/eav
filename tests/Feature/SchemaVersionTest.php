<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\AttributeGroupRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaVersion;
use Jurager\Eav\Translation\TranslationRepository;
use Mockery;
use RuntimeException;

/** What the schema every process holds in memory follows: the shared schema version. */
class SchemaVersionTest extends FeatureTestCase
{
    private AttributeGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = AttributeGroup::create(['code' => 'dimensions', 'sort' => 1]);
    }

    private function nextRequest(): AttributeGroupRegistry
    {
        app()->forgetScopedInstances();

        return app(AttributeGroupRegistry::class);
    }

    public function test_a_change_past_the_package_is_picked_up_after_eav_clear(): void
    {
        app(AttributeGroupRegistry::class)->all();

        DB::table('attribute_groups')->where('id', $this->group->id)->update(['code' => 'renamed']);

        $this->assertSame('dimensions', $this->nextRequest()->get($this->group->id)->code, 'Nothing announced the change.');

        $this->artisan('eav:clear')->assertSuccessful();

        $this->assertSame('renamed', $this->nextRequest()->get($this->group->id)->code);
    }

    public function test_a_change_of_labels_moves_the_version(): void
    {
        $en = $this->createLocale('en');
        $before = app(SchemaVersion::class)->current();

        app(TranslationRepository::class)->save($this->group, [['locale_id' => $en->id, 'label' => 'Dimensions']]);

        $this->assertNotSame($before, app(SchemaVersion::class)->current());
    }

    public function test_a_locale_written_through_its_model_moves_the_version(): void
    {
        $before = app(SchemaVersion::class)->current();

        $locale = Locale::create(['code' => 'kk', 'name' => 'Kazakh']);
        $created = app(SchemaVersion::class)->current();

        $locale->delete();

        $this->assertNotSame($before, $created, 'A seeder creating a locale is seen by every process.');
        $this->assertNotSame($created, app(SchemaVersion::class)->current());
    }

    public function test_narrowing_the_request_survives_a_locale_change(): void
    {
        $this->createLocale('en');
        app(LocaleRegistry::class)->set(['en']);

        $this->createLocale('de');

        $this->assertSame(['en'], app(LocaleRegistry::class)->get());
    }

    public function test_the_version_is_read_once_per_request(): void
    {
        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('get')->once()->andReturn('v1');
        $version = new SchemaVersion($cache);

        $version->current();
        $version->current();

        $this->assertSame('v1', $version->current());
    }

    public function test_an_unreachable_cache_leaves_the_schema_readable(): void
    {
        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('get')->andThrow(new RuntimeException('connection refused'));
        $cache->shouldReceive('forever')->andThrow(new RuntimeException('connection refused'));
        $this->app->instance(SchemaVersion::class, new SchemaVersion($cache));

        $registry = app(AttributeGroupRegistry::class);
        $registry->forget();

        $this->assertSame('dimensions', $registry->get($this->group->id)->code);
    }
}
