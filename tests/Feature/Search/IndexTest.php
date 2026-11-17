<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Jurager\Eav\Enums\IndexType;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Engines\Meilisearch\Settings;
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

        $settings = app(Settings::class)->for(Search::index(SearchableProduct::class));

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

        $settings = app(Settings::class)->for(Search::index(SearchableProduct::class));

        $this->assertSame(['attributes.article'], $settings['typoTolerance']['disableOnAttributes']);
    }

    public function test_configured_settings_are_merged_under_the_derived_ones(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);

        config([
            'scout.meilisearch.index-settings' => [SearchableProduct::class => ['filterableAttributes' => ['legacy']]],
            'eav.search.engines.meilisearch.settings' => ['typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4]], 'stopWords' => ['the']],
            'eav.search.engines.meilisearch.indexes.searchable_product' => ['stopWords' => ['a'], 'synonyms' => ['tv' => ['television']]],
            'eav.search.engines.meilisearch.locales' => ['ru' => 'uk'],
        ]);

        $settings = app(Settings::class)->for(Search::index(SearchableProduct::class));

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

        $settings = app(Settings::class)->for(Search::index(SearchableProduct::class));

        $this->assertContains('attributes.title.xx', $settings['searchableAttributes']);
        $this->assertSame(['en', 'ru'], array_merge(...array_column($settings['localizedAttributes'], 'locales')));
    }

    public function test_sync_command_pushes_settings_to_the_engine(): void
    {
        $this->attribute('number', 'price', ['filterable' => true]);

        $this->artisan('eav:sync-index-settings', ['type' => 'searchable_product'])->assertSuccessful();

        $this->assertContains('attributes.price', $this->engine->settings['eav_test_products']['filterableAttributes']);
    }

    public function test_sync_command_covers_every_searchable_type(): void
    {
        $this->artisan('eav:sync-index-settings')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['eav_test_products', 'eav_test_categories'], array_keys($this->engine->settings));
    }
}
