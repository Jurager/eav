<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Eav\Contracts\ShouldUseNestedSet;

/** {@see TreeCategory} whose ancestors are found through nested-set bounds (`_lft`/`_rgt`). */
class NestedTreeCategory extends TreeCategory implements ShouldUseNestedSet
{
    public function getLftName(): string
    {
        return '_lft';
    }

    public function getRgtName(): string
    {
        return '_rgt';
    }
}
