<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class TrashableProduct extends SearchableProduct
{
    use SoftDeletes;

    public function getEntityType(): string
    {
        return 'trashable_product';
    }
}
