<?php

namespace Jurager\Eav\Events;

use Jurager\Eav\Models\AttributeEnum;

class AttributeEnumUpdated
{
    public function __construct(public readonly AttributeEnum $enum) {}
}
