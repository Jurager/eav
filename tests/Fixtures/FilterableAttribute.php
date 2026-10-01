<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Filterable\Concerns\HasFilterable;

class FilterableAttribute extends \Jurager\Eav\Models\Attribute
{
    use HasFilterable;

    protected $table = 'attributes';
}
