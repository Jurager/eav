<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Eav\Search\Index\Index;

/**
 * A product with a price per price type, indexed as one keyed field.
 */
class PricedProduct extends SearchableProduct
{
    public function getEntityType(): string
    {
        return 'priced_product';
    }

    public function searchableAs(): string
    {
        return 'eav_test_priced';
    }

    public function searchIndex(Index $index): void
    {
        $index->field('name')->searchable();

        $index->field('prices')
            ->number()
            ->keyed()
            ->filterable()
            ->sortable()
            ->alias('price')
            ->using(static fn (self $product): array => [
                'retail' => strlen($product->name) * 10,
                'wholesale' => 100 - strlen($product->name),
            ]);
    }
}
