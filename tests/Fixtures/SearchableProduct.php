<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Eav\Concerns\HasSearchableAttributes;

class SearchableProduct extends Product
{
    use HasSearchableAttributes;

    public function attributeEntityType(): string
    {
        return 'searchable_product';
    }

    public function getScoutKey(): mixed
    {
        return $this->id;
    }
}
