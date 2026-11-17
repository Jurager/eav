<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Eav\Concerns\HasSearchableAttributes;
use Jurager\Eav\Search\Index\Index;

class SearchableProduct extends Product
{
    use HasSearchableAttributes;

    public function getEntityType(): string
    {
        return 'searchable_product';
    }

    public function searchableAs(): string
    {
        return 'eav_test_products';
    }

    public function searchIndex(Index $index): void
    {
        $index->field('name')->searchable()->sortable();
        $index->field('parent_id')->number()->filterable()->alias('parent');
    }
}
