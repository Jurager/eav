<?php

namespace Jurager\Eav\Events;

use Jurager\Eav\Models\AttributeEnum;

class AttributeEnumDeleted
{
    public function __construct(public readonly AttributeEnum $enum) {}
}
