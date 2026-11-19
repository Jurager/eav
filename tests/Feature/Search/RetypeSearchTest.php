<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Search;

use Jurager\Eav\Enums\CorrectionReason;
use Jurager\Eav\Facades\Search;
use Jurager\Eav\Search\Correction;
use Jurager\Eav\Tests\Fixtures\Layouts;
use Jurager\Eav\Tests\Fixtures\SearchableCategory;
use Jurager\Eav\Tests\Fixtures\SearchableProduct;

class RetypeSearchTest extends SearchTestCase
{
    private const string PRODUCTS = 'eav_test_products';

    protected function setUp(): void
    {
        parent::setUp();

        config(['eav.search.layouts' => [Layouts::RUSSIAN]]);
    }

    /**
     * @return list<string|null>
     */
    private function texts(): array
    {
        return array_column($this->engine->queries(), 'q');
    }

    public function test_a_search_that_finds_nothing_is_run_again_for_the_other_layout(): void
    {
        $product = $this->product('Шкаф');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'шкаф');

        $results = SearchableProduct::search('irfa')->retype()->paginate();

        $this->assertSame([$product->id], $results->modelKeys());
        $this->assertEquals(new Correction('irfa', 'шкаф', CorrectionReason::Layout), $results->correction());
        $this->assertSame(['irfa', 'шкаф'], $this->texts());
    }

    public function test_then_for_the_latin_letters(): void
    {
        $product = $this->product('Samsung');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'samsung');

        $results = SearchableProduct::search('самсунг')->retype()->paginate();

        $this->assertSame([$product->id], $results->modelKeys());
        $this->assertEquals(new Correction('самсунг', 'samsung', CorrectionReason::Transliteration), $results->correction());
        $this->assertSame(['самсунг', 'cfvceyu', 'samsung'], $this->texts());
    }

    public function test_the_latin_letters_are_those_of_the_language_searched(): void
    {
        SearchableProduct::search('шкаф')->locale('ru')->retype()->paginate();

        $russian = $this->texts();
        $this->engine->searches = [];

        SearchableProduct::search('шкаф')->retype()->paginate();

        $this->assertSame(['шкаф', 'irfa', 'shkaf'], $russian);
        $this->assertSame(['шкаф', 'irfa', 'skaf'], $this->texts());
    }

    public function test_the_next_layout_is_tried_when_one_finds_nothing(): void
    {
        config(['eav.search.layouts' => [Layouts::RUSSIAN, Layouts::GREEK]]);

        $product = $this->product('Ιρφα');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'ιρφα');

        $results = SearchableProduct::search('irfa')->retype()->paginate();

        $this->assertEquals(new Correction('irfa', 'ιρφα', CorrectionReason::Layout), $results->correction());
        $this->assertSame(['irfa', 'шкаф', 'ιρφα'], $this->texts());
    }

    public function test_the_first_text_that_finds_something_ends_the_search(): void
    {
        config(['eav.search.layouts' => [Layouts::RUSSIAN, Layouts::GREEK]]);

        $product = $this->product('Шкаф');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'шкаф');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'ιρφα');

        SearchableProduct::search('irfa')->retype()->paginate();

        $this->assertSame(['irfa', 'шкаф'], $this->texts());
    }

    public function test_without_layouts_only_the_letters_are_retyped(): void
    {
        config(['eav.search.layouts' => []]);

        SearchableProduct::search('irfa')->retype()->paginate();
        SearchableProduct::search('самсунг')->retype()->paginate();

        $this->assertSame(['irfa', 'самсунг', 'samsung'], $this->texts());
    }

    public function test_a_search_that_finds_something_is_not_retyped(): void
    {
        $product = $this->product('Irfa');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]]);

        $results = SearchableProduct::search('irfa')->retype()->paginate();

        $this->assertNull($results->correction());
        $this->assertSame(['irfa'], $this->texts());
    }

    public function test_a_search_that_did_not_ask_is_not_retyped(): void
    {
        $product = $this->product('Шкаф');
        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'шкаф');

        $results = SearchableProduct::search('irfa')->paginate();

        $this->assertSame(0, $results->total());
        $this->assertSame(['irfa'], $this->texts());
    }

    public function test_a_search_that_finds_nothing_in_any_form_stays_empty(): void
    {
        $results = SearchableProduct::search('самсунг')->retype()->paginate();

        $this->assertSame(0, $results->total());
        $this->assertNull($results->correction());
        $this->assertSame(['самсунг', 'cfvceyu', 'samsung'], $this->texts());
    }

    public function test_the_retyped_search_keeps_the_conditions_the_page_and_the_links(): void
    {
        $products = [$this->product('Шкаф'), $this->product('Шкаф большой')];
        $this->engine->respond(self::PRODUCTS, array_map(fn ($product) => ['id' => $product->id], $products), text: 'шкаф');

        $results = SearchableProduct::search('irfa')->where('parent', null)->retype()->paginate(1, 'page', 2);

        [$typed, $retyped] = $this->engine->queries();

        $this->assertSame([$products[1]->id], $results->modelKeys());
        $this->assertSame($typed['filter'], $retyped['filter']);
        $this->assertSame([1, 2], [$retyped['hitsPerPage'], $retyped['page']]);
        $this->assertStringContainsString('query=irfa', $results->url(1));
    }

    public function test_what_the_engine_told_of_the_retyped_text_is_kept(): void
    {
        $product = $this->product('Samsung');
        $this->engine->respond(self::PRODUCTS, [[
            'id' => $product->id,
            '_rankingScoreDetails' => ['words' => ['matchingWords' => 1, 'maxMatchingWords' => 1], 'typo' => ['typoCount' => 1]],
            '_formatted' => ['name' => '<em>Samsung</em>'],
        ]], text: 'samsunk');

        $results = SearchableProduct::search('самсунк')->correction()->retype()->paginate();

        $this->assertEquals(new Correction('самсунк', 'samsung', CorrectionReason::Transliteration), $results->correction());
    }

    public function test_a_federated_search_is_run_again_for_every_search_with_the_same_text(): void
    {
        $product = $this->product('Шкаф');
        $category = SearchableCategory::create(['name' => 'Шкаф']);

        $this->engine->respond(self::PRODUCTS, [['id' => $product->id]], text: 'шкаф');
        $this->engine->respond('eav_test_categories', [['id' => $category->id]], text: 'шкаф');

        $results = Search::federated([
            SearchableProduct::search('irfa')->retype(),
            SearchableCategory::search('irfa'),
        ])->paginate(5);

        $this->assertSame(['searchable_product', 'searchable_category'], $results->getCollection()->map->getEntityType()->all());
        $this->assertEquals(new Correction('irfa', 'шкаф', CorrectionReason::Layout), $results->correction());
        $this->assertSame([['irfa', 'irfa'], ['шкаф', 'шкаф']], array_map(fn (array $sent) => array_column($sent['queries'], 'q'), $this->engine->searches));
    }

    public function test_an_engine_that_cannot_federate_retypes_the_whole_list(): void
    {
        $fake = Search::fake();
        $product = $this->product('Шкаф');

        $fake->respond('searchable_product', [['id' => $product->id]], text: 'шкаф');

        $results = Search::federated([
            SearchableProduct::search('irfa')->retype(),
            SearchableCategory::search('irfa'),
        ])->paginate();

        $this->assertEquals(new Correction('irfa', 'шкаф', CorrectionReason::Layout), $results->correction());
        $this->assertSame(['irfa', 'irfa', 'шкаф', 'шкаф'], array_map(fn ($search) => $search->text(), $fake->searches));
    }
}
