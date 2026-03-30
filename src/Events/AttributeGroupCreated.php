<?php

namespace Jurager\Eav\Events;

use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupCreated
{
    public function __construct(public readonly AttributeGroup $group) {}
}
