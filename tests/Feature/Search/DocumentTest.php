<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Support\Facades\Queue;
use Jurager\Eav\Registries\EnumRegistry;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\Jobs\MakeSearchable;

class DocumentTest extends SearchTestCase
{
    public function test_document_holds_the_key_and_declared_fields(): void
    {
        $parent = $this->product('Model');
        $product = $this->product('Widget', $parent->id);

        $this->assertSame(
            ['id' => $product->id, 'name' => 'Widget', 'parent_id' => $parent->id],
            $product->toSearchableArray(),
        );
    }

    public function test_an_entity_without_indexed_values_is_still_indexed(): void
    {
        $this->attribute('text', 'notes');

        $product = $this->product();
        $product->eav()->set('notes', 'Internal')->save();

        $this->assertTrue($product->shouldBeSearchable());
        $this->assertArrayNotHasKey('attributes', $product->fresh()->toSearchableArray());
    }

    public function test_indexed_attributes_are_nested_under_attributes(): void
    {
        $this->attribute('text', 'sku', ['searchable' => true]);
        $this->attribute('number', 'price', ['filterable' => true]);
        $this->attribute('number', 'weight', ['sortable' => true]);
        $this->attribute('text', 'notes');

        $product = $this->product();
        $product->eav()->set('sku', 'AB-1')->set('price', 100)->set('weight', 3)->set('notes', 'x')->save();

        $this->assertEquals(
            ['sku' => 'AB-1', 'price' => 100, 'weight' => 3],
            $product->fresh()->toSearchableArray()['attributes'],
        );
    }

    public function test_localizable_values_are_keyed_by_locale_code(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);

        $product = $this->product();
        $product->eav()->set('title', 'Red shoes', $this->en->id)->set('title', 'Красные кроссовки', $this->ru->id)->save();

        $this->assertSame(
            ['title' => ['en' => 'Red shoes', 'ru' => 'Красные кроссовки']],
            $product->fresh()->toSearchableArray()['attributes'],
        );
    }

    public function test_a_select_is_indexed_by_code_with_labels_per_locale(): void
    {
        $color = $this->attribute('select', 'color', ['filterable' => true, 'searchable' => true]);
        $red = $this->option($color, 'red', ['en' => 'Red', 'ru' => 'Красный']);

        $product = $this->product();
        $product->eav()->set('color', $red->id)->save();

        $document = $product->fresh()->toSearchableArray();

        $this->assertSame(['color' => 'red'], $document['attributes']);
        $this->assertSame(['color' => ['en' => 'Red', 'ru' => 'Красный']], $document['labels']);
    }

    public function test_every_locale_is_indexed_whichever_one_the_request_runs_in(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        $color = $this->attribute('select', 'color', ['filterable' => true, 'searchable' => true]);
        $red = $this->option($color, 'red', ['en' => 'Red', 'ru' => 'Красный']);

        $product = $this->product();
        $product->eav()->set('title', 'Shoes', $this->en->id)->set('title', 'Кеды', $this->ru->id)->set('color', $red->id)->save();

        app(LocaleRegistry::class)->set(['ru']);
        app(EnumRegistry::class)->forget($color->id);

        // As Scout indexes it, with values already loaded in the active locale only.
        $loaded = SearchableProduct::query()->with('attribute_values.translations')->whereKey($product->id)->get();
        $document = $loaded->first()->makeSearchableUsing($loaded)->first()->toSearchableArray();

        $this->assertSame(['en' => 'Shoes', 'ru' => 'Кеды'], $document['attributes']['title']);
        $this->assertSame(['en' => 'Red', 'ru' => 'Красный'], $document['labels']['color']);
    }

    public function test_a_document_built_directly_ignores_values_loaded_in_the_active_locale(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);

        $product = $this->product();
        $product->eav()->set('title', 'Shoes', $this->en->id)->set('title', 'Кеды', $this->ru->id)->save();

        app(LocaleRegistry::class)->set(['ru']);

        $loaded = SearchableProduct::query()->with('attribute_values.translations')->findOrFail($product->id);

        $this->assertSame(['en' => 'Shoes', 'ru' => 'Кеды'], $loaded->toSearchableArray()['attributes']['title']);
        $this->assertCount(1, $loaded->attribute_values->first()->translations, 'The values the caller loaded are left as they were.');
    }

    public function test_dates_are_indexed_as_timestamps_and_booleans_as_booleans(): void
    {
        $this->attribute('date', 'released', ['filterable' => true]);
        $this->attribute('boolean', 'active', ['filterable' => true]);

        $product = $this->product();
        $product->eav()->set('released', '2026-01-02 00:00:00')->set('active', false)->save();

        $attributes = $product->fresh()->toSearchableArray()['attributes'];

        $this->assertSame(strtotime('2026-01-02 00:00:00'), $attributes['released']);
        $this->assertFalse($attributes['active']);
    }

    public function test_a_variant_document_includes_inherited_values(): void
    {
        $this->attribute('text', 'title', ['searchable' => true, 'inherit_from_parent' => true]);

        $parent = $this->product('Model');
        $parent->eav()->set('title', 'Parent title')->save();
        $variant = $this->product('Variant', $parent->id);

        $this->assertSame('Parent title', $variant->fresh()->toSearchableArray()['attributes']['title']);
    }

    public function test_a_save_reindexes_only_when_a_declared_column_changed(): void
    {
        $product = $this->product();

        $this->assertTrue($product->searchIndexShouldBeUpdated());

        $product = $product->fresh();
        $product->touch();
        $this->assertFalse($product->searchIndexShouldBeUpdated());

        $product->update(['name' => 'Gadget']);
        $this->assertTrue($product->searchIndexShouldBeUpdated());
    }

    public function test_saving_an_entity_with_values_indexes_it_once(): void
    {
        config(['scout.queue' => true]);
        $this->attribute('text', 'sku', ['searchable' => true]);
        Queue::fake();

        $product = new SearchableProduct(['name' => 'Widget']);
        $product->eav()->set('sku', 'AB-1');
        $product->save();

        Queue::assertPushed(MakeSearchable::class, 1);
    }

    public function test_saving_an_entity_without_values_still_indexes_it(): void
    {
        config(['scout.queue' => true]);
        Queue::fake();

        $this->product();

        Queue::assertPushed(MakeSearchable::class, 1);
    }

    public function test_clearing_a_value_that_was_never_stored_does_not_skip_indexing(): void
    {
        config(['scout.queue' => true]);
        $this->attribute('text', 'sku', ['searchable' => true]);
        Queue::fake();

        $product = new SearchableProduct(['name' => 'Widget']);
        $product->eav()->set('sku', null);
        $product->save();

        Queue::assertPushed(MakeSearchable::class, 1);
    }
}
