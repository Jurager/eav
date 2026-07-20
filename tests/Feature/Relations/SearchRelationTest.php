<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Relations\SearchRelation;
use Jurager\Eav\Search\SearchResult;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\Product;

class SearchRelationTest extends FeatureTestCase
{
    public function test_get_results_hydrates_in_relevance_order_and_runs_the_after_search_hook(): void
    {
        $first = $this->createProduct('First');
        $second = $this->createProduct('Second');

        // Relevance order is reversed from insertion order — proves paginate()'s
        // hit-order sort is honored rather than falling back to primary key order.
        $result = new SearchResult(ids: [$second->id, $first->id], total: 2, facets: ['color' => ['red' => 1]]);
        $relation = new StubSearchRelation(Product::query(), $first, $result);

        $models = $relation->getResults();

        $this->assertSame([$second->id, $first->id], $models->pluck('id')->all());
        $this->assertSame($result, $relation->afterSearchCalledWith);
    }

    public function test_match_resolves_independently_per_parent_rather_than_batching(): void
    {
        $product = $this->createProduct('Widget');

        $result = new SearchResult(ids: [$product->id], total: 1, facets: []);
        $relation = new StubSearchRelation(Product::query(), $product, $result);

        $parents = [$this->createProduct('A'), $this->createProduct('B')];

        $matched = $relation->match($parents, new Collection(), 'items');

        foreach ($matched as $model) {
            $this->assertSame([$product->id], $model->getRelation('items')->pluck('id')->all());
        }

        $this->assertSame(2, $relation->searchCallCount);
    }

    public function test_init_relation_seeds_an_empty_collection_before_resolution(): void
    {
        $product = $this->createProduct('Widget');
        $result = new SearchResult(ids: [], total: 0, facets: []);
        $relation = new StubSearchRelation(Product::query(), $product, $result);

        $initialized = $relation->initRelation([$product], 'items');

        $this->assertInstanceOf(Collection::class, $initialized[0]->getRelation('items'));
        $this->assertTrue($initialized[0]->getRelation('items')->isEmpty());
    }

    public function test_get_eager_never_runs_a_search(): void
    {
        $product = $this->createProduct('Widget');
        $result = new SearchResult(ids: [$product->id], total: 1, facets: []);
        $relation = new StubSearchRelation(Product::query(), $product, $result);

        $this->assertTrue($relation->getEager()->isEmpty());
        $this->assertSame(0, $relation->searchCallCount);
    }
}

class StubSearchRelation extends SearchRelation
{
    public int $searchCallCount = 0;

    public ?SearchResult $afterSearchCalledWith = null;

    public function __construct(\Illuminate\Database\Eloquent\Builder $query, Model $parent, private readonly SearchResult $result)
    {
        parent::__construct($query, $parent);
    }

    protected function search(Model $parent): SearchResult
    {
        $this->searchCallCount++;

        return $this->result;
    }

    protected function relatedModelClass(): string
    {
        return Product::class;
    }

    protected function perPage(): int
    {
        return 15;
    }

    protected function page(): int
    {
        return 1;
    }

    protected function afterSearch(SearchResult $result): void
    {
        $this->afterSearchCalledWith = $result;
    }
}
