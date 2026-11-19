<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Jurager\Eav\Enums\CorrectionReason;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Correction;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;

class CorrectionSearchTest extends SearchTestCase
{
    private const string PRODUCTS = 'eav_test_products';

    /**
     * A hit as the engine tells it when asked for the ranking details and the highlights.
     *
     * @return array<string, mixed>
     */
    private function hit(int $id, string $highlighted, int $typos = 0, int $found = 1, int $asked = 1): array
    {
        return [
            'id' => $id,
            '_rankingScoreDetails' => [
                'words' => ['matchingWords' => $found, 'maxMatchingWords' => $asked],
                'typo' => ['typoCount' => $typos],
            ],
            '_formatted' => ['name' => $highlighted],
        ];
    }

    public function test_the_engine_is_asked_for_the_ranking_details_and_the_highlights_of_the_searched_fields(): void
    {
        SearchableProduct::search('shoes')->correction()->paginate();

        $request = $this->engine->queries()[0];

        $this->assertTrue($request['showRankingScoreDetails']);
        $this->assertSame(['name'], $request['attributesToHighlight']);
        $this->assertSame($request['attributesToSearchOn'], $request['attributesToHighlight']);
    }

    public function test_the_fields_named_to_highlight_are_highlighted_besides_the_searched_ones(): void
    {
        SearchableProduct::search('shoes')->highlight('parent')->correction()->paginate();

        $this->assertSame(['name', 'parent_id'], $this->engine->queries()[0]['attributesToHighlight']);
    }

    public function test_a_search_without_text_has_nothing_to_correct(): void
    {
        $product = $this->product();
        $this->engine->respond(self::PRODUCTS, [$this->hit($product->id, '<em>Widget</em>', typos: 1)]);

        $results = SearchableProduct::search()->correction()->paginate();

        $this->assertArrayNotHasKey('showRankingScoreDetails', $this->engine->queries()[0]);
        $this->assertArrayNotHasKey('attributesToHighlight', $this->engine->queries()[0]);
        $this->assertNull($results->correction());
    }

    public function test_a_search_that_did_not_ask_reports_no_correction(): void
    {
        $product = $this->product('Shoes');
        $this->engine->respond(self::PRODUCTS, [$this->hit($product->id, '<em>Shoes</em>', typos: 1)]);

        $results = SearchableProduct::search('shoez')->paginate();

        $this->assertArrayNotHasKey('showRankingScoreDetails', $this->engine->queries()[0]);
        $this->assertNull($results->correction());
        $this->assertArrayNotHasKey('correction', $results->toArray());
    }

    public function test_a_typo_the_engine_put_up_with_is_reported(): void
    {
        $product = $this->product('Shoes');
        $this->engine->respond(self::PRODUCTS, [$this->hit($product->id, '<em>Shoes</em>', typos: 1)]);

        $results = SearchableProduct::search('shoez')->correction()->paginate();

        $this->assertEquals(new Correction('shoez', 'shoes', CorrectionReason::Typo), $results->correction());
        $this->assertSame(['original' => 'shoez', 'corrected' => 'shoes', 'reason' => 'typo'], $results->toArray()['correction']);
        $this->assertCount(1, $this->engine->queries(), 'The best hit is on the page: the engine is not asked again.');
    }

    public function test_a_word_the_engine_skipped_is_reported(): void
    {
        $product = $this->product('Red shoes');
        $this->engine->respond(self::PRODUCTS, [$this->hit($product->id, '<em>Red</em> shoes', found: 1, asked: 2)]);

        $results = SearchableProduct::search('red zzzz')->correction()->paginate();

        $this->assertEquals(new Correction('red zzzz', 'red', CorrectionReason::Partial), $results->correction());
    }

    public function test_a_text_found_as_it_is_written_is_not_corrected(): void
    {
        $product = $this->product('Shoes');
        $this->engine->respond(self::PRODUCTS, [$this->hit($product->id, '<em>Shoes</em>')]);

        $this->assertNull(SearchableProduct::search('shoes')->correction()->paginate()->correction());
    }

    public function test_a_page_after_the_first_asks_the_engine_for_the_best_hit(): void
    {
        [$first, $second, $third] = [$this->product('Shoes'), $this->product('Shoes red'), $this->product('Shoes blue')];

        $this->engine->respond(self::PRODUCTS, [
            $this->hit($first->id, '<em>Shoes</em>', typos: 1),
            $this->hit($second->id, '<em>Shoes</em> red', typos: 1),
            $this->hit($third->id, '<em>Shoes</em> blue'),
        ]);

        $results = SearchableProduct::search('shoez')->correction()->paginate(1, 'page', 2);

        $this->assertSame([$second->id], $results->modelKeys());
        $this->assertSame('shoes', $results->correction()?->corrected);

        [$page, $best] = $this->engine->queries();

        $this->assertSame([1, 2], [$page['hitsPerPage'], $page['page']]);
        $this->assertSame([1, 1], [$best['hitsPerPage'], $best['page']]);
        $this->assertArrayNotHasKey('facets', $best);
    }

    public function test_a_federated_search_judges_by_the_best_hit_of_the_whole_list(): void
    {
        $product = $this->product('Shoes');
        $category = SearchableCategory::create(['name' => 'Shoes']);

        $this->engine->respond(self::PRODUCTS, [$this->hit($product->id, '<em>Shoes</em>', typos: 1)]);
        $this->engine->respond('eav_test_categories', [['id' => $category->id]]);

        $federated = fn () => Search::federated([
            SearchableProduct::search('shoez')->correction(),
            SearchableCategory::search('shoez'),
        ]);

        $this->assertEquals(new Correction('shoez', 'shoes', CorrectionReason::Typo), $federated()->paginate(2)->correction());
        $this->assertCount(1, $this->engine->searches, 'The best hit is on the page: the engine is not asked again.');

        $this->assertEquals(new Correction('shoez', 'shoes', CorrectionReason::Typo), $federated()->paginate(1, 'page', 2)->correction());
        $this->assertSame(
            [['limit' => 2, 'offset' => 0], ['limit' => 1, 'offset' => 1], ['limit' => 1, 'offset' => 0]],
            array_column($this->engine->searches, 'federation'),
        );
    }

    public function test_an_engine_that_cannot_federate_has_the_best_ranked_hit_judged(): void
    {
        $fake = Search::fake();

        $product = $this->product('Shoes');
        $category = SearchableCategory::create(['name' => 'Shoes']);

        $fake->respond('searchable_product', [['_rankingScore' => 0.9] + $this->hit($product->id, '<em>Shoes</em>', typos: 1)]);
        $fake->respond('searchable_category', [['id' => $category->id, '_rankingScore' => 0.5]]);

        $federated = fn (float $weight) => Search::federated([
            SearchableProduct::search('shoez')->correction(),
            SearchableCategory::search('shoez')->correction()->weight($weight),
        ]);

        $this->assertSame(CorrectionReason::Typo, $federated(1)->paginate(1)->correction()?->reason);
        $this->assertSame(CorrectionReason::Typo, $federated(1)->paginate(1, 'page', 2)->correction()?->reason);

        // 0.5 × 2 outranks 0.9, and the category was found as it was written.
        $this->assertNull($federated(2)->paginate(1)->correction());
    }
}
