<?php

namespace Jurager\Eav\Events;

use Jurager\Eav\Models\Attribute;

class AttributeUpdated
{
    public function __construct(public readonly Attribute $attribute) {}
}
