<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Support\Facades\Queue;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Resolvers\AttributeRelationFilterResolver;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\Jobs\RemoveFromSearch;

class BuilderTest extends SearchTestCase
{
    private const string PRODUCTS = 'eav_test_products';

    protected function setUp(): void
    {
        parent::setUp();

        $this->attribute('text', 'title', ['searchable' => true, 'localizable' => true]);
        $this->attribute('text', 'sku', ['searchable' => true, 'filterable' => true]);
        $this->attribute('number', 'price', ['filterable' => true, 'sortable' => true]);
        $color = $this->attribute('select', 'color', ['filterable' => true, 'searchable' => true]);
        $this->option($color, 'red', ['en' => 'Red', 'ru' => 'Красный']);
        $this->option($color, 'blue', ['en' => 'Blue']);
    }

    private function request(int $position = 0): array
    {
        return $this->engine->queries()[$position];
    }

    public function test_text_is_matched_against_searchable_fields_of_the_current_locale(): void
    {
        Search::for(SearchableProduct::class)->query('shoes')->paginate();

        $request = $this->request();

        $this->assertSame(self::PRODUCTS, $request['indexUid']);
        $this->assertSame('shoes', $request['q']);
        $this->assertSame(['name', 'attributes.title.en', 'attributes.sku', 'labels.color.en'], $request['attributesToSearchOn']);
        $this->assertSame(['en'], $request['locales']);
        $this->assertSame(15, $request['hitsPerPage']);
        $this->assertSame(1, $request['page']);
    }

    public function test_locale_switches_the_fields_and_language_searched(): void
    {
        Search::for(SearchableProduct::class)->query('кеды')->locale('ru')->paginate();

        $this->assertContains('attributes.title.ru', $this->request()['attributesToSearchOn']);
        $this->assertNotContains('attributes.title.en', $this->request()['attributesToSearchOn']);
        $this->assertSame(['ru'], $this->request()['locales']);
    }

    public function test_several_locales_are_searched_together(): void
    {
        Search::for(SearchableProduct::class)->query('x')->locale('ru', 'en')->paginate();

        $this->assertContains('attributes.title.ru', $this->request()['attributesToSearchOn']);
        $this->assertContains('attributes.title.en', $this->request()['attributesToSearchOn']);
        $this->assertSame(['ru', 'en'], $this->request()['locales']);
    }

    public function test_a_locale_that_does_not_exist_is_a_422(): void
    {
        $this->expectExceptionMessage('locale [de] is not available');

        Search::for(SearchableProduct::class)->query('x')->locale('de')->paginate();
    }

    public function test_search_on_narrows_the_fields(): void
    {
        Search::for(SearchableProduct::class)->query('x')->searchOn('title')->paginate();

        $this->assertSame(['attributes.title.en'], $this->request()['attributesToSearchOn']);
    }

    public function test_without_text_no_search_fields_are_sent(): void
    {
        Search::for(SearchableProduct::class)->paginate();

        $this->assertArrayNotHasKey('q', $this->request());
        $this->assertArrayNotHasKey('attributesToSearchOn', $this->request());
    }

    public function test_conditions_accumulate_and_each_is_parenthesised(): void
    {
        Search::for(SearchableProduct::class)
            ->where('color', 'red')
            ->where('price', '>=', 100)
            ->whereIn('sku', ['A', 'B'])
            ->whereBetween('price', [1, 5])
            ->whereNull('parent')
            ->filter(['price' => ['lt' => 500], 'or' => [['color' => 'blue'], ['sku' => 'C']]])
            ->paginate();

        $this->assertSame(
            '(attributes.color = "red") AND (attributes.price >= 100) AND (attributes.sku IN ["A", "B"])'
            .' AND ((attributes.price >= 1 AND attributes.price <= 5)) AND (parent_id NOT EXISTS)'
            .' AND (attributes.price < 500 AND (attributes.color = "blue" OR attributes.sku = "C"))',
            $this->request()['filter'],
        );
    }

    public function test_an_unknown_filter_field_is_a_422(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        Search::for(SearchableProduct::class)->filter(['nope' => 1])->paginate();
    }

    public function test_sort_accepts_several_fields_and_directions(): void
    {
        Search::for(SearchableProduct::class)->sort('-price,name')->paginate();

        $this->assertSame(['attributes.price:desc', 'name:asc'], $this->request()['sort']);
    }

    public function test_sorting_by_a_field_that_is_not_sortable_is_a_422(): void
    {
        $this->expectExceptionMessage('field [sku] is not sortable');

        Search::for(SearchableProduct::class)->sort('sku')->paginate();
    }

    public function test_options_highlight_and_distinct_reach_the_engine(): void
    {
        Search::for(SearchableProduct::class)
            ->query('x')
            ->highlight('title')
            ->matching('all')
            ->threshold(0.5)
            ->distinct('parent')
            ->options(['showMatchesPosition' => true])
            ->paginate();

        $request = $this->request();

        $this->assertSame(['attributes.title.en'], $request['attributesToHighlight']);
        $this->assertSame('all', $request['matchingStrategy']);
        $this->assertSame(0.5, $request['rankingScoreThreshold']);
        $this->assertSame('parent_id', $request['distinct']);
        $this->assertTrue($request['showMatchesPosition']);
    }

    public function test_results_are_models_in_ranking_order_with_their_hits(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');

        $this->engine->respond(self::PRODUCTS, [
            ['id' => $second->id, 'name' => 'Second', '_rankingScore' => 0.9, '_formatted' => ['name' => '<em>Second</em>']],
            ['id' => 999, 'name' => 'Gone'],
            ['id' => $first->id, 'name' => 'First', 'attributes' => ['title' => ['en' => 'One']]],
        ], total: 40);

        $results = Search::for(SearchableProduct::class)->query('x')->paginate(3);

        $this->assertSame([$second->id, $first->id], $results->modelKeys());
        $this->assertSame(40, $results->total());
        $this->assertSame(14, $results->lastPage());
        $this->assertSame([$second->id, 999, $first->id], $results->keys());

        /** @var Hit $hit */
        $hit = $results->hits()->first();

        $this->assertTrue($hit->model->is($second));
        $this->assertSame(0.9, $hit->score);
        $this->assertSame('<em>Second</em>', $hit->highlight('name'));
        $this->assertSame('One', $results->hits()->last()->get('title'));
        $this->assertNull($results->hits()[1]->model);
    }

    public function test_documents_of_entities_that_are_gone_are_removed_from_the_index(): void
    {
        config(['scout.queue' => true]);

        $kept = $this->product('Kept');
        $hidden = $this->product('Hidden');
        $this->engine->respond(self::PRODUCTS, [['id' => $kept->id], ['id' => $hidden->id], ['id' => 999]]);

        Queue::fake();

        Search::for(SearchableProduct::class)->loadUsing(fn ($query) => $query->whereKey($kept->id))->paginate();

        // Left out by the load query, but still there: only the key no row has is removed.
        Queue::assertPushed(RemoveFromSearch::class, 1);
        Queue::assertPushed(RemoveFromSearch::class, fn (RemoveFromSearch $job) => $job->models->modelKeys() == [999]);
    }

    public function test_pruning_can_be_turned_off(): void
    {
        config(['scout.queue' => true, 'eav.search.prune' => false]);
        $this->engine->respond(self::PRODUCTS, [['id' => 999]]);
        Queue::fake();

        Search::for(SearchableProduct::class)->paginate();

        Queue::assertNotPushed(RemoveFromSearch::class);
    }

    public function test_get_first_keys_and_count(): void
    {
        $product = $this->product();
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], total: 7);

        $search = Search::for(SearchableProduct::class);

        $this->assertTrue($search->get()->first()->is($product));
        $this->assertTrue($search->first()->is($product));
        $this->assertSame([$product->id], $search->keys());
        $this->assertSame(7, $search->facets('color')->count());
        $this->assertSame(0, $this->request(3)['hitsPerPage']);
        $this->assertArrayNotHasKey('facets', $this->request(3));
    }

    public function test_hydration_can_be_customised(): void
    {
        $product = $this->product();
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]]);

        $results = Search::for(SearchableProduct::class)
            ->with('parent')
            ->loadUsing(fn ($query) => $query->where('name', 'Other'))
            ->paginate();

        $this->assertCount(0, $results->items());
        $this->assertCount(1, $results->hits());
    }

    public function test_facets_are_keyed_by_name_with_labels_and_ranges(): void
    {
        $this->engine->respond(
            self::PRODUCTS,
            [],
            facets: ['attributes.color' => ['red' => 3, 'blue' => 1], 'attributes.price' => ['100' => 4]],
            stats: ['attributes.price' => ['min' => 100, 'max' => 900]],
        );

        $results = Search::for(SearchableProduct::class)->facets(['color', 'price'])->locale('ru')->paginate();

        $this->assertSame(['attributes.color', 'attributes.price'], $this->request()['facets']);
        $this->assertSame([
            'color' => ['type' => 'terms', 'values' => [
                ['value' => 'red', 'label' => 'Красный', 'count' => 3],
                ['value' => 'blue', 'label' => 'blue', 'count' => 1],
            ]],
            'price' => ['type' => 'range', 'min' => 100, 'max' => 900],
        ], $results->facets());
        $this->assertSame($results->facets(), $results->toArray()['facets']);
    }

    public function test_a_filtered_facet_is_counted_without_its_own_filter(): void
    {
        Search::for(SearchableProduct::class)
            ->where('color', 'red')
            ->where('price', '<', 500)
            ->facets(['color', 'sku'])
            ->paginate();

        $this->assertCount(2, $this->engine->queries());

        $facet = $this->request(1);

        $this->assertSame('(attributes.price < 500)', $facet['filter']);
        $this->assertSame(['attributes.color'], $facet['facets']);
        $this->assertSame(0, $facet['limit']);
    }

    public function test_a_facet_must_be_filterable(): void
    {
        $this->expectExceptionMessage('field [title] is not filterable');

        Search::for(SearchableProduct::class)->facets('title')->paginate();
    }

    public function test_facet_values_can_be_searched(): void
    {
        $this->engine->respond(self::PRODUCTS, [], facets: ['attributes.color' => ['red' => 2]]);

        $values = Search::for(SearchableProduct::class)->where('color', 'blue')->where('price', '>', 1)->facetValues('color', 're');

        $this->assertSame([['value' => 'red', 'label' => 'Red', 'count' => 2]], $values);
        $this->assertSame(
            ['facetName' => 'attributes.color', 'facetQuery' => 're', 'filter' => '(attributes.price > 1)'],
            $this->engine->facetSearches[0]['query'],
        );
    }

    public function test_partition_leads_with_matches_and_tops_up_from_the_rest(): void
    {
        $a = $this->product('A');
        $this->engine->respond(self::PRODUCTS, [['id' => $a->id]], total: 1);

        Search::for(SearchableProduct::class)
            ->where('price', '>', 0)
            ->partition(['color' => 'red'])
            ->paginate(2);

        [$main, $leading, $trailing] = $this->engine->queries();

        $this->assertSame(0, $main['hitsPerPage']);
        $this->assertSame('(attributes.price > 0)', $main['filter']);
        $this->assertSame('(attributes.price > 0) AND (attributes.color = "red")', $leading['filter']);
        $this->assertSame(2, $leading['hitsPerPage']);
        $this->assertSame('(attributes.price > 0) AND (NOT (attributes.color = "red"))', $trailing['filter']);
        $this->assertSame(1, $trailing['limit']);
        $this->assertSame(0, $trailing['offset']);
    }

    public function test_federated_search_merges_entity_types_into_one_list(): void
    {
        $product = $this->product('Shoes');
        $category = SearchableCategory::create(['name' => 'Shoes']);

        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], facets: ['attributes.color' => ['red' => 1]]);
        $this->engine->respond('eav_test_categories', [['id' => $category->id]]);

        $results = Search::federated([
            Search::for(SearchableProduct::class)->query('shoes')->where('price', '>', 1)->facets('color')->weight(2),
            Search::for(SearchableCategory::class)->query('shoes'),
        ])->paginate(10);

        $sent = $this->engine->searches[0];

        $this->assertSame(['limit' => 10, 'offset' => 0, 'facetsByIndex' => [self::PRODUCTS => ['attributes.color']]], $sent['federation']);
        $this->assertSame(['weight' => 2.0], $sent['queries'][0]['federationOptions']);
        $this->assertSame('(attributes.price > 1)', $sent['queries'][0]['filter']);
        $this->assertArrayNotHasKey('hitsPerPage', $sent['queries'][0]);
        $this->assertArrayNotHasKey('facets', $sent['queries'][0]);

        $this->assertTrue($results->items()[0]->is($product));
        $this->assertTrue($results->items()[1]->is($category));
        $this->assertSame(['searchable_product', 'searchable_category'], $results->hits()->pluck('entityType')->all());
        $this->assertSame('Red', $results->facets()['searchable_product']['color']['values'][0]['label']);
        $this->assertSame(2, $results->total());
    }

    public function test_a_relation_attribute_filter_needs_its_resolver(): void
    {
        $this->expectExceptionMessage('field [parent.sku] is not part of the search index');

        Search::for(SearchableProduct::class)->filter(['parent' => ['sku' => 'AB']])->paginate();
    }

    public function test_the_relation_resolver_turns_a_related_attribute_into_ids(): void
    {
        config(['eav.search.resolvers' => [AttributeRelationFilterResolver::class]]);

        $parent = $this->product('Model');
        $parent->eav()->set('sku', 'AB')->save();

        Search::for(SearchableProduct::class)->filter(['parent' => ['sku' => 'AB']])->paginate();

        $this->assertSame("(parent_id IN [{$parent->id}])", $this->request()['filter']);
    }

    public function test_a_relation_filter_matching_nothing_matches_no_entity(): void
    {
        config(['eav.search.resolvers' => [AttributeRelationFilterResolver::class]]);

        Search::for(SearchableProduct::class)->filter(['parent' => ['sku' => 'none']])->paginate();

        $this->assertSame('(parent_id IN [])', $this->request()['filter']);
    }

    public function test_the_relation_resolver_refuses_too_many_matches(): void
    {
        config(['eav.search.resolvers' => [AttributeRelationFilterResolver::class], 'eav.search.relation_limit' => 1]);

        foreach (['A', 'B'] as $name) {
            $this->product($name)->eav()->set('sku', 'AB')->save();
        }

        $this->expectExceptionMessage('matches more than 1 related records');

        Search::for(SearchableProduct::class)->filter(['parent' => ['sku' => 'AB']])->paginate();
    }
}
