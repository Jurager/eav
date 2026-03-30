<?php

namespace Jurager\Eav\Events;

use Jurager\Eav\Models\Attribute;

class AttributeDeleted
{
    public function __construct(public readonly Attribute $attribute) {}
}
