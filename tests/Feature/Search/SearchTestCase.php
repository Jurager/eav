<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\RecordingMeilisearchEngine;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;

abstract class SearchTestCase extends FeatureTestCase
{
    /**
     * The Meilisearch engine with a recording in place of the server: what it would send, and what it answers.
     */
    protected RecordingMeilisearchEngine $engine;

    protected Locale $en;

    protected Locale $ru;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap([
            'searchable_product' => SearchableProduct::class,
            'searchable_category' => SearchableCategory::class,
        ]);

        Schema::create('search_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->en = $this->createLocale('en');
        $this->ru = $this->createLocale('ru');

        $this->useRecordingEngine();
    }

    /**
     * Make Scout's Meilisearch engine the driver, with a recording in place of the server.
     */
    protected function useRecordingEngine(): void
    {
        $engine = $this->engine = new RecordingMeilisearchEngine((bool) config('scout.soft_delete', false));

        // The manager binds the closure to itself, so the engine is passed in rather than read off the test.
        $this->app->make(EngineManager::class)->extend('meilisearch', static fn () => $engine);

        config(['scout.driver' => 'meilisearch']);
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScoutServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('search_categories');

        parent::tearDown();
    }

    /**
     * Create an attribute of the searchable product.
     *
     * @param  array<string, mixed>  $flags
     */
    protected function attribute(string $type, string $code, array $flags = []): Attribute
    {
        $typeModel = AttributeType::query()->firstWhere('code', $type) ?? $this->createAttributeType($type);

        return $this->createAttribute($typeModel, ['code' => $code, 'entity_types' => ['searchable_product'], ...$flags]);
    }

    /**
     * Create a select option with a label per locale.
     *
     * @param  array<string, string>  $labels  Labels keyed by locale code.
     */
    protected function option(Attribute $attribute, string $code, array $labels = []): AttributeEnum
    {
        $enum = $this->createEnum($attribute, $code);

        foreach ($labels as $locale => $label) {
            $enum->translations()->attach($this->{$locale}->id, ['label' => $label]);
        }

        return $enum;
    }

    protected function product(string $name = 'Widget', ?int $parentId = null): SearchableProduct
    {
        return SearchableProduct::create(['name' => $name, 'parent_id' => $parentId]);
    }
}
