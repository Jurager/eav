<?php

declare(strict_types=1);

namespace Jurager\Eav\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jurager\Eav\Models\AttributeType;

class AttributeTypeUpdated
{
    use Dispatchable;

    public function __construct(public readonly AttributeType $type)
    {
    }
}
