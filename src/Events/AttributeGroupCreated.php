<?php

declare(strict_types=1);

namespace Jurager\Eav\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupCreated
{
    use Dispatchable;

    public function __construct(public readonly AttributeGroup $group)
    {
    }
}
