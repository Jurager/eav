<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Contracts\Console\Kernel;
use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Grammars\MeilisearchGrammar;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Registries\AttributeRegistry;
use Jurager\Eav\Tests\Fixtures\Product;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;

class IndexTest extends SearchTestCase
{
    public function test_index_holds_declared_fields_then_indexed_attributes(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true, 'sort' => 2]);
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true, 'sort' => 1]);
        $this->attribute('text', 'notes');

        $index = Search::index(SearchableProduct::class);

        $this->assertSame(
            ['id', 'name', 'parent_id', 'attributes.price', 'attributes.title'],
            array_keys($index->fields()),
        );
        $this->assertSame('eav_test_products', $index->uid());
        $this->assertSame('searchable_product', $index->entityType);
    }

    public function test_a_field_is_found_by_path_alias_or_attribute_code(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);

        $index = Search::index('searchable_product');

        $this->assertSame('attributes.price', $index->find('price')->path);
        $this->assertSame('attributes.price', $index->find('attributes.price')->path);
        $this->assertSame('parent_id', $index->find('parent')->path);
        $this->assertNull($index->find('nope'));
    }

    public function test_a_declared_field_wins_over_an_attribute_of_the_same_name(): void
    {
        $this->attribute('text', 'name', ['filterable' => true]);

        $this->assertSame('name', Search::index(SearchableProduct::class)->find('name')->path);
    }

    public function test_field_types_follow_the_attribute_type(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);
        $this->attribute('date', 'released', ['filterable' => true]);
        $this->attribute('boolean', 'active', ['filterable' => true]);
        $this->attribute('select', 'color', ['filterable' => true, 'searchable' => true]);

        $index = Search::index(SearchableProduct::class);

        $this->assertSame(IndexType::Number, $index->find('price')->type);
        $this->assertSame(IndexType::Date, $index->find('released')->type);
        $this->assertSame(IndexType::Boolean, $index->find('active')->type);
        $this->assertSame(IndexType::Keyword, $index->find('color')->type);
        $this->assertTrue($index->find('labels.color')->localized);
    }

    public function test_option_labels_do_not_collide_with_an_attribute_named_like_them(): void
    {
        $this->attribute('select', 'color', ['searchable' => true, 'filterable' => true]);
        $this->attribute('text', 'color_label', ['searchable' => true]);

        $index = Search::index(SearchableProduct::class);

        $this->assertSame('attributes.color_label', $index->find('color_label')->path);
        $this->assertSame('labels.color', $index->find('labels.color')->path);
    }

    public function test_the_index_follows_schema_changes(): void
    {
        $this->assertNull(Search::index(SearchableProduct::class)->find('price'));

        $this->attribute('number', 'price', ['filterable' => true]);

        $this->assertNotNull(Search::index(SearchableProduct::class)->find('price'));
    }

    public function test_the_index_follows_schema_changes_of_earlier_requests(): void
    {
        $price = $this->attribute('number', 'price', ['filterable' => true]);
        Search::index(SearchableProduct::class);

        $price->update(['filterable' => false]);
        $this->attribute('text', 'title', ['searchable' => true]);
        app()->forgetScopedInstances();

        $this->assertSame(
            ['id', 'name', 'parent_id', 'attributes.title'],
            array_keys(Search::index(SearchableProduct::class)->fields()),
        );
    }

    public function test_indexed_attributes_are_picked_once_while_the_registry_holds_the_same_definitions(): void
    {
        $price = $this->attribute('number', 'price', ['filterable' => true]);
        Search::index(SearchableProduct::class);

        app(AttributeRegistry::class)->get('searchable_product', $price->id)->setAttribute('filterable', false);
        app()->forgetScopedInstances();

        $this->assertNotNull(Search::index(SearchableProduct::class)->find('price'), 'Nothing announced the change.');
    }

    public function test_a_model_that_is_not_searchable_has_no_index(): void
    {
        $this->expectException(SearchNotAvailableException::class);

        Search::index(Product::class);
    }

    public function test_settings_are_derived_from_the_index(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        $this->attribute('text', 'sku', ['searchable' => true, 'filterable' => true]);
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);
        $this->attribute('select', 'color', ['filterable' => true, 'searchable' => true]);

        $settings = app(MeilisearchGrammar::class)->compileSettings(Search::index(SearchableProduct::class));

        $this->assertSame([
            'name',
            'attributes.title.en', 'attributes.title.ru',
            'attributes.sku',
            'labels.color.en', 'labels.color.ru',
        ], $settings['searchableAttributes']);

        $this->assertSame(
            ['id', 'parent_id', 'attributes.sku', 'attributes.price', 'attributes.color'],
            $settings['filterableAttributes'],
        );
        $this->assertSame(['id', 'name', 'attributes.price'], $settings['sortableAttributes']);
        $this->assertSame([
            ['attributePatterns' => ['*.en'], 'locales' => ['en']],
            ['attributePatterns' => ['*.ru'], 'locales' => ['ru']],
        ], $settings['localizedAttributes']);
        $this->assertArrayNotHasKey('typoTolerance', $settings);
    }

    public function test_typos_are_off_for_searchable_fields_that_are_not_text(): void
    {
        $this->attribute('number', 'article', ['searchable' => true]);

        $settings = app(MeilisearchGrammar::class)->compileSettings(Search::index(SearchableProduct::class));

        $this->assertSame(['attributes.article'], $settings['typoTolerance']['disableOnAttributes']);
    }

    public function test_configured_settings_are_merged_under_the_derived_ones(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);

        config([
            'scout.meilisearch.index-settings' => [SearchableProduct::class => [
                'filterableAttributes' => ['legacy'],
                'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4]],
                'stopWords' => ['a'],
                'synonyms' => ['tv' => ['television']],
            ]],
        ]);

        $settings = app(MeilisearchGrammar::class)->compileSettings(Search::index(SearchableProduct::class));

        $this->assertSame(['id', 'parent_id', 'attributes.price', 'legacy'], $settings['filterableAttributes']);
        $this->assertSame(['oneTypo' => 4], $settings['typoTolerance']['minWordSizeForTypos']);
        $this->assertSame(['a'], $settings['stopWords']);
        $this->assertSame(['tv' => ['television']], $settings['synonyms']);
        $this->assertArrayNotHasKey('localizedAttributes', $settings);
    }

    public function test_a_locale_the_engine_has_no_language_for_is_left_out(): void
    {
        $this->createLocale('xx');
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);

        $settings = app(MeilisearchGrammar::class)->compileSettings(Search::index(SearchableProduct::class));

        $this->assertContains('attributes.title.xx', $settings['searchableAttributes']);
        $this->assertSame(['en', 'ru'], array_merge(...array_column($settings['localizedAttributes'], 'locales')));
    }

    public function test_settings_configured_for_scout_by_index_name_are_read_too(): void
    {
        config(['scout.meilisearch.index-settings' => ['eav_test_products' => ['stopWords' => ['the']]]]);

        $this->assertSame(['the'], app(MeilisearchGrammar::class)->compileSettings(Search::index(SearchableProduct::class))['stopWords']);
    }

    public function test_derived_settings_reach_the_engine_through_scout(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);

        $this->app->call([new SyncIndexSettings('searchable_product'), 'handle']);
        $this->app->call([new SyncIndexSettings('product'), 'handle']);

        $this->assertContains('attributes.price', $this->engine->settings['eav_test_products']['filterableAttributes']);
        $this->assertArrayNotHasKey('eav_test_categories', $this->engine->settings);
    }

    public function test_scouts_settings_command_syncs_the_derived_settings(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);
        config(['scout.meilisearch.index-settings' => ['eav_test_products' => ['stopWords' => ['the']]]]);

        // Laravel announces a starting command only when run from the command line: do as it does there.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();

        $this->artisan('scout:sync-index-settings')->assertSuccessful();

        $this->assertContains('attributes.price', $this->engine->settings['eav_test_products']['filterableAttributes']);
        $this->assertSame(['the'], $this->engine->settings['eav_test_products']['stopWords']);
        $this->assertArrayHasKey('eav_test_categories', $this->engine->settings);
    }
}
