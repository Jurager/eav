<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Filterable\Concerns\HasFilterable;

class FilterableProduct extends Product
{
    use HasFilterable;
}
