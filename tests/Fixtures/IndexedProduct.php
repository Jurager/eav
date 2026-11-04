<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Eav\Search\Contracts\InteractsWithIndex;

class IndexedProduct extends Product implements InteractsWithIndex
{
    public function getEntityType(): string
    {
        return 'indexed_product';
    }

    public function indexAliases(): array
    {
        return ['categories.category_id' => 'categories.id'];
    }

    public function indexFields(): array
    {

        return [];

    }
}
