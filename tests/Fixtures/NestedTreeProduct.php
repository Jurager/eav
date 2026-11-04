<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

/** {@see TreeProduct} placed in nested-set categories. */
class NestedTreeProduct extends TreeProduct
{
    protected static function attributeScopeModel(): ?string
    {
        return NestedTreeCategory::class;
    }
}
