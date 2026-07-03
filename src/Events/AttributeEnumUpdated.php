<?php

namespace Jurager\Eav\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jurager\Eav\Models\AttributeEnum;

class AttributeEnumUpdated
{
    use Dispatchable;

    public function __construct(public readonly AttributeEnum $enum)
    {
    }
}
