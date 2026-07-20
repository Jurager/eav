<?php

declare(strict_types=1);

namespace Jurager\Eav\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Jurager\Eav\Models\AttributeEnum;

class AttributeEnumCreated
{
    use Dispatchable;

    public function __construct(public readonly AttributeEnum $enum)
    {
    }
}
