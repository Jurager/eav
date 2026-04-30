<?php

namespace Jurager\Eav\Events;

use Jurager\Eav\Models\AttributeEnum;

class AttributeEnumCreated
{
    public function __construct(public readonly AttributeEnum $enum)
    {
    }
}
