<?php

namespace Jurager\Eav\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jurager\Eav\Models\Attribute;

class AttributeUpdated
{
    use Dispatchable;

    public function __construct(public readonly Attribute $attribute)
    {
    }
}
