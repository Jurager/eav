<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Feature\Relations;

use Illuminate\Database\Eloquent\Collection;
use Jurager\Eav\Relations\ClosureRelation;
use Jurager\Eav\Tests\Feature\FeatureTestCase;
use Jurager\Eav\Tests\Fixtures\Product;

class ClosureRelationTest extends FeatureTestCase
{
    private function relation(): ClosureRelation
    {
        // Unsaved - stands in for the parent identity only, must not show up
        // among the "related" rows the resolver below queries from the same table.
        $parent = new Product(['name' => 'Parent']);

        return new ClosureRelation(Product::query(), $parent, fn () => Product::query());
    }

    public function test_get_results_returns_everything_the_resolver_yields(): void
    {
        $this->createProduct('Widget A');
        $this->createProduct('Widget B');

        $results = $this->relation()->getResults();

        $this->assertCount(2, $results);
    }

    /**
     * The real-world usage (Jurager\Filterable's included-relation scoping) does
     * `$relation = $model->someRelation(); $relation->filter([...]); $relation->getResults();`
     * — two separate calls on the same instance. `where()` stands in for `filter()`
     * here so this test doesn't need jurager/filterable's Attribute fixtures wired up.
     */
    public function test_chained_constraint_narrows_get_results(): void
    {
        $this->createProduct('Widget A');
        $this->createProduct('Widget B');

        $relation = $this->relation();
        $relation->where('name', 'Widget A');

        $results = $relation->getResults();

        $this->assertCount(1, $results);
        $this->assertSame('Widget A', $results->first()->name);
    }

    /** Chaining through __call() must return the constrained builder, not just apply it silently. */
    public function test_call_returns_the_query_it_constrained(): void
    {
        $this->createProduct('Widget A');
        $this->createProduct('Widget B');

        $constrained = $this->relation()->where('name', 'Widget A');

        $this->assertCount(1, $constrained->get());
    }

    /**
     * Per-instance memoization (needed for the chain above) must not leak across
     * different parents resolved via match() - each parent gets its own query.
     */
    public function test_match_resolves_independently_per_parent(): void
    {
        $ownerA = $this->createProduct('Owner A');
        $ownerB = $this->createProduct('Owner B');
        $this->createProduct('Child of A');
        $this->createProduct('Child of B');

        $resolver = fn (Product $parent) => Product::query()
            ->where('name', $parent->is($ownerA) ? 'Child of A' : 'Child of B');

        $relation = new ClosureRelation(Product::query(), $ownerA, $resolver);

        $relation->match([$ownerA, $ownerB], new Collection, 'sibling');

        $this->assertSame('Child of A', $ownerA->getRelation('sibling')->first()->name);
        $this->assertSame('Child of B', $ownerB->getRelation('sibling')->first()->name);
    }
}
