<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Filters\RelationFilterResolver;
use Jurager\Eav\Search\Grammars\MeilisearchGrammar;
use Jurager\Eav\Tests\Fixtures\PricedProduct;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;
use Laravel\Scout\Jobs\RemoveFromSearch;
use LogicException;

class MeilisearchEngineTest extends SearchTestCase
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
        SearchableProduct::search('shoes')->paginate();

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
        SearchableProduct::search('кеды')->locale('ru')->paginate();

        $this->assertContains('attributes.title.ru', $this->request()['attributesToSearchOn']);
        $this->assertNotContains('attributes.title.en', $this->request()['attributesToSearchOn']);
        $this->assertSame(['ru'], $this->request()['locales']);
    }

    public function test_several_locales_are_searched_together(): void
    {
        SearchableProduct::search('x')->locale('ru', 'en')->paginate();

        $this->assertContains('attributes.title.ru', $this->request()['attributesToSearchOn']);
        $this->assertContains('attributes.title.en', $this->request()['attributesToSearchOn']);
        $this->assertSame(['ru', 'en'], $this->request()['locales']);
    }

    public function test_a_locale_that_does_not_exist_is_a_422(): void
    {
        $this->expectExceptionMessage('locale [de] is not available');

        SearchableProduct::search('x')->locale('de')->paginate();
    }

    public function test_search_on_narrows_the_fields(): void
    {
        SearchableProduct::search('x')->searchOn('title')->paginate();

        $this->assertSame(['attributes.title.en'], $this->request()['attributesToSearchOn']);
    }

    public function test_without_text_no_search_fields_are_sent(): void
    {
        SearchableProduct::search()->paginate();

        $this->assertArrayNotHasKey('q', $this->request());
        $this->assertArrayNotHasKey('attributesToSearchOn', $this->request());
    }

    public function test_conditions_accumulate_and_each_is_parenthesised(): void
    {
        SearchableProduct::search()
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

        SearchableProduct::search()->filter(['nope' => 1])->paginate();
    }

    public function test_sort_accepts_several_fields_and_directions(): void
    {
        SearchableProduct::search()->sort('-price,name')->paginate();

        $this->assertSame(['attributes.price:desc', 'name:asc'], $this->request()['sort']);
    }

    public function test_sorting_by_a_field_that_is_not_sortable_is_a_422(): void
    {
        $this->expectExceptionMessage('field [sku] is not sortable');

        SearchableProduct::search()->sort('sku')->paginate();
    }

    public function test_options_highlight_and_distinct_reach_the_engine(): void
    {
        SearchableProduct::search('x')
            ->highlight('title')
            ->distinct('parent')
            ->options(['matchingStrategy' => 'all', 'rankingScoreThreshold' => 0.5])
            ->paginate();

        $request = $this->request();

        $this->assertSame(['attributes.title.en'], $request['attributesToHighlight']);
        $this->assertSame('all', $request['matchingStrategy']);
        $this->assertSame(0.5, $request['rankingScoreThreshold']);
        $this->assertSame('parent_id', $request['distinct']);
    }

    public function test_results_are_models_in_ranking_order_carrying_what_the_engine_told(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');

        $this->engine->respond(self::PRODUCTS, [
            ['id' => $second->id, 'name' => 'Second', '_rankingScore' => 0.9, '_formatted' => ['name' => '<em>Second</em>']],
            ['id' => 999, 'name' => 'Gone'],
            ['id' => $first->id, 'name' => 'First'],
        ], total: 40);

        $results = SearchableProduct::search('x')->paginate(3);

        $this->assertSame([$second->id, $first->id], $results->modelKeys());
        $this->assertSame(40, $results->total());
        $this->assertSame(14, $results->lastPage());
        $this->assertStringContainsString('query=x', $results->url(2));

        // Scout keeps the engine's own keys on the model; highlights are read through the index definition.
        $this->assertSame(0.9, $results->first()->scoutMetadata()['_rankingScore']);
        $this->assertSame('<em>Second</em>', $results->first()->highlighted('name'));
        $this->assertSame('First', $results->last()->highlighted('name'));
    }

    public function test_documents_of_entities_that_are_gone_are_removed_from_the_index(): void
    {
        config(['scout.queue' => true]);

        $kept = $this->product('Kept');
        $hidden = $this->product('Hidden');
        $this->engine->respond(self::PRODUCTS, [['id' => $kept->id], ['id' => $hidden->id], ['id' => 999]]);

        Queue::fake();

        SearchableProduct::search()->query(fn ($query) => $query->whereKey($kept->id))->paginate();

        // Left out by the load query, but still there: only the key no row has is removed.
        Queue::assertPushed(RemoveFromSearch::class, 1);
        Queue::assertPushed(RemoveFromSearch::class, fn (RemoveFromSearch $job) => $job->models->modelKeys() == [999]);
    }

    public function test_pruning_can_be_turned_off(): void
    {
        config(['scout.queue' => true, 'eav.search.prune' => false]);
        $this->engine->respond(self::PRODUCTS, [['id' => 999]]);
        Queue::fake();

        SearchableProduct::search()->paginate();

        Queue::assertNotPushed(RemoveFromSearch::class);
    }

    public function test_get_first_keys_and_count(): void
    {
        $product = $this->product();
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], total: 7);

        $search = SearchableProduct::search();

        $this->assertTrue($search->get()->first()->is($product));
        $this->assertTrue($search->first()->is($product));
        $this->assertSame([$product->id], $search->keys()->all());
        $this->assertSame(7, $search->facets('color')->count());
        $this->assertSame(0, $this->request(3)['hitsPerPage']);
        $this->assertArrayNotHasKey('facets', $this->request(3));
    }

    public function test_hydration_can_be_customised(): void
    {
        $product = $this->product();
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]]);

        $results = SearchableProduct::search()
            ->query(fn ($query) => $query->with('parent'))
            ->query(fn ($query) => $query->where('name', 'Other'))
            ->paginate();

        // As in Scout, a query callback that narrows the models narrows the total with them.
        $this->assertCount(0, $results->items());
        $this->assertSame(0, $results->total());
    }

    public function test_facets_are_keyed_by_name_with_labels_and_ranges(): void
    {
        $this->engine->respond(
            self::PRODUCTS,
            [],
            facets: ['attributes.color' => ['red' => 3, 'blue' => 1], 'attributes.price' => ['100' => 4]],
            stats: ['attributes.price' => ['min' => 100, 'max' => 900]],
        );

        $results = SearchableProduct::search()->facets(['color', 'price'])->locale('ru')->paginate();

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
        SearchableProduct::search()
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

        SearchableProduct::search()->facets('title')->paginate();
    }

    public function test_facet_values_can_be_searched(): void
    {
        $this->engine->respond(self::PRODUCTS, [], facets: ['attributes.color' => ['red' => 2]]);

        $values = SearchableProduct::search()->where('color', 'blue')->where('price', '>', 1)->facetValues('color', 're');

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

        SearchableProduct::search()
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

    public function test_a_boost_fetches_the_hits_through_a_weighted_federation(): void
    {
        $product = $this->product();
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], total: 7);

        $results = SearchableProduct::search('shoes')
            ->where('price', '>', 1)
            ->boost(['color' => 'red'], 3)
            ->facets('sku')
            ->paginate(5, 'page', 2);

        [$count, $hits] = $this->engine->searches;

        // The total and the facets come from the plain search; the hits from the search and its boosted part.
        $this->assertNull($count['federation']);
        $this->assertSame(0, $count['queries'][0]['hitsPerPage']);
        $this->assertSame(['attributes.sku'], $count['queries'][0]['facets']);

        $this->assertSame(['limit' => 5, 'offset' => 5], $hits['federation']);
        $this->assertSame('(attributes.price > 1)', $hits['queries'][0]['filter']);
        $this->assertArrayNotHasKey('federationOptions', $hits['queries'][0]);
        $this->assertSame('(attributes.price > 1) AND (attributes.color = "red")', $hits['queries'][1]['filter']);
        $this->assertSame(['weight' => 3.0], $hits['queries'][1]['federationOptions']);
        $this->assertSame('shoes', $hits['queries'][1]['q']);

        $this->assertSame(7, $results->total());
    }

    public function test_a_boost_has_no_effect_where_relevance_does_not_rank(): void
    {
        SearchableProduct::search()->boost(['color' => 'red'])->paginate();
        SearchableProduct::search('x')->boost(['color' => 'red'])->sort('price')->paginate();
        SearchableProduct::search('x')->boost(['color' => 'red'])->count();

        $this->assertCount(3, $this->engine->searches);

        foreach ($this->engine->searches as $search) {
            $this->assertNull($search['federation']);
            $this->assertCount(1, $search['queries']);
        }
    }

    public function test_a_search_is_either_boosted_or_partitioned(): void
    {
        foreach ([
            fn ($search) => $search->boost(['color' => 'red'])->partition(['color' => 'blue']),
            fn ($search) => $search->partition(['color' => 'blue'])->boost(['color' => 'red']),
        ] as $attempt) {
            try {
                $attempt(SearchableProduct::search());
                $this->fail('Boosting a partitioned search should be refused.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('partitioned and boosted', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);

        SearchableProduct::search()->boost(['color' => 'red'], 0);
    }

    public function test_a_boosted_search_keeps_its_boosts_within_a_federation(): void
    {
        $product = $this->product();
        $category = SearchableCategory::create(['name' => 'Shoes']);

        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]]);
        $this->engine->respond('eav_test_categories', [['id' => $category->id]]);

        $results = Search::federated([
            SearchableProduct::search('phone')->boost(['color' => 'red'], 3)->weight(2),
            SearchableCategory::search('phone'),
        ])->paginate();

        $queries = $this->engine->searches[0]['queries'];

        $this->assertSame([self::PRODUCTS, self::PRODUCTS, 'eav_test_categories'], array_column($queries, 'indexUid'));
        $this->assertSame(['weight' => 2.0], $queries[0]['federationOptions']);
        $this->assertSame(['weight' => 6.0], $queries[1]['federationOptions']);
        $this->assertSame('(attributes.color = "red")', $queries[1]['filter']);

        // Hits of either products query belong to the products search.
        $this->assertSame(
            ['searchable_product', 'searchable_product', 'searchable_category'],
            $results->getCollection()->map->getEntityType()->all(),
        );
    }

    public function test_a_filtered_facet_of_a_federated_search_is_counted_without_its_own_filter(): void
    {
        $this->engine->respond(self::PRODUCTS, [], facets: ['attributes.color' => ['red' => 2, 'blue' => 5]]);

        $results = Search::federated([
            SearchableProduct::search('x')->where('color', 'red')->where('price', '>', 1)->facets(['color', 'sku']),
            SearchableCategory::search('x'),
        ])->paginate();

        [$federated, $facets] = $this->engine->searches;

        // Facets are asked apart from the hits: every facet as the search stands, and the filtered one
        // once more without its own filter.
        $this->assertSame(['limit' => 15, 'offset' => 0], $federated['federation']);
        $this->assertNull($facets['federation']);
        $this->assertCount(2, $facets['queries']);
        $this->assertSame(['attributes.color', 'attributes.sku'], $facets['queries'][0]['facets']);
        $this->assertSame('(attributes.color = "red") AND (attributes.price > 1)', $facets['queries'][0]['filter']);
        $this->assertSame('(attributes.price > 1)', $facets['queries'][1]['filter']);
        $this->assertSame(['attributes.color'], $facets['queries'][1]['facets']);
        $this->assertSame(['red', 'blue'], array_column($results->facets()['searchable_product']['color']['values'], 'value'));
    }

    public function test_a_partition_leads_within_a_federated_search(): void
    {
        $products = [$this->product('A'), $this->product('B'), $this->product('C')];
        $category = SearchableCategory::create(['name' => 'Shoes']);

        $this->engine->respond(self::PRODUCTS, array_map(fn ($product) => ['id' => $product->id], $products));
        $this->engine->respond('eav_test_categories', [['id' => $category->id]]);

        Search::federated([
            SearchableProduct::search()->where('price', '>', 1)->partition(['color' => 'red']),
            SearchableCategory::search(),
        ])->paginate(5, 'page', 2);

        [$leading, $trailing] = $this->engine->searches;

        // What leads: the matching side of the partition, and the search that has none.
        $this->assertSame(['limit' => 5, 'offset' => 5], $leading['federation']);
        $this->assertSame([self::PRODUCTS, 'eav_test_categories'], array_column($leading['queries'], 'indexUid'));
        $this->assertSame('(attributes.price > 1) AND (attributes.color = "red")', $leading['queries'][0]['filter']);

        // What trails: the rest of the partitioned search, topping the page up from where the leaders ended.
        $this->assertSame([self::PRODUCTS], array_column($trailing['queries'], 'indexUid'));
        $this->assertSame('(attributes.price > 1) AND (NOT (attributes.color = "red"))', $trailing['queries'][0]['filter']);
        $this->assertSame(['limit' => 5, 'offset' => 1], $trailing['federation']);
    }

    public function test_a_keyed_field_is_filtered_sorted_and_faceted_by_its_keys(): void
    {
        Relation::morphMap(['priced_product' => PricedProduct::class]);

        PricedProduct::search()
            ->where('prices.retail', '>=', '50')
            ->filter(['price.wholesale' => ['lt' => 90]])
            ->sort('-prices.retail')
            ->facets('prices.retail')
            ->paginate();

        $request = $this->request();

        $this->assertSame('(prices.retail >= 50) AND (prices.wholesale < 90)', $request['filter']);
        $this->assertSame(['prices.retail:desc'], $request['sort']);
        $this->assertSame(['prices.retail'], $request['facets']);

        $index = Search::index(PricedProduct::class);
        $settings = app(MeilisearchGrammar::class)->compileSettings($index);

        // The engine is told about the field once: its keys follow from it.
        $this->assertSame(['id', 'prices'], $settings['filterableAttributes']);
        $this->assertSame(['id', 'prices'], $settings['sortableAttributes']);
        $this->assertSame($index->find('prices.retail'), $index->find('price.retail'));
        $this->assertSame(['retail' => 10, 'wholesale' => 99], PricedProduct::create(['name' => 'A'])->toSearchableArray()['prices']);
    }

    public function test_only_a_plain_key_of_a_keyed_field_is_a_field(): void
    {
        Relation::morphMap(['priced_product' => PricedProduct::class]);

        foreach (['prices.retail OR id > 0', 'prices.', 'prices.a.b c', 'name.first', 'prices'] as $name) {
            try {
                PricedProduct::search()->where($name, '>', 1);

                $this->assertSame('prices', $name, "[{$name}] should not be a field.");
            } catch (InvalidSearchQueryException $e) {
                $this->assertStringContainsString('not part of the search index', $e->getMessage());
            }
        }
    }

    public function test_federated_search_merges_entity_types_into_one_list(): void
    {
        $product = $this->product('Shoes');
        $category = SearchableCategory::create(['name' => 'Shoes']);

        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], facets: ['attributes.color' => ['red' => 1]]);
        $this->engine->respond('eav_test_categories', [['id' => $category->id]]);

        $results = Search::federated([
            SearchableProduct::search('shoes')->where('price', '>', 1)->facets('color')->weight(2),
            SearchableCategory::search('shoes'),
        ])->paginate(10);

        $sent = $this->engine->searches[0];

        $this->assertSame(['limit' => 10, 'offset' => 0], $sent['federation']);
        $this->assertSame(['weight' => 2.0], $sent['queries'][0]['federationOptions']);
        $this->assertSame('(attributes.price > 1)', $sent['queries'][0]['filter']);
        $this->assertArrayNotHasKey('hitsPerPage', $sent['queries'][0]);
        $this->assertArrayNotHasKey('facets', $sent['queries'][0]);

        $this->assertTrue($results->items()[0]->is($product));
        $this->assertTrue($results->items()[1]->is($category));
        $this->assertSame(['searchable_product', 'searchable_category'], $results->getCollection()->map->getEntityType()->all());
        $this->assertSame('Red', $results->facets()['searchable_product']['color']['values'][0]['label']);
        $this->assertSame(2, $results->total());
    }

    public function test_a_relation_attribute_filter_needs_its_resolver(): void
    {
        $this->expectExceptionMessage('field [parent.sku] is not part of the search index');

        SearchableProduct::search()->filter(['parent' => ['sku' => 'AB']])->paginate();
    }

    public function test_the_relation_resolver_turns_a_related_attribute_into_ids(): void
    {
        config(['eav.search.resolvers' => [RelationFilterResolver::class]]);

        $parent = $this->product('Model');
        $parent->eav()->set('sku', 'AB')->save();

        SearchableProduct::search()->filter(['parent' => ['sku' => 'AB']])->paginate();

        $this->assertSame("(parent_id IN [{$parent->id}])", $this->request()['filter']);
    }

    public function test_a_relation_filter_matching_nothing_matches_no_entity(): void
    {
        config(['eav.search.resolvers' => [RelationFilterResolver::class]]);

        SearchableProduct::search()->filter(['parent' => ['sku' => 'none']])->paginate();

        $this->assertSame('(parent_id IN [])', $this->request()['filter']);
    }

    public function test_the_relation_resolver_refuses_too_many_matches(): void
    {
        config(['eav.search.resolvers' => [RelationFilterResolver::class], 'eav.search.relation_limit' => 1]);

        foreach (['A', 'B'] as $name) {
            $this->product($name)->eav()->set('sku', 'AB')->save();
        }

        $this->expectExceptionMessage('matches more than 1 related records');

        SearchableProduct::search()->filter(['parent' => ['sku' => 'AB']])->paginate();
    }
}
