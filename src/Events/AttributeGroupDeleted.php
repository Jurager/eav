<?php

declare(strict_types=1);

namespace Jurager\Eav\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Jurager\Eav\Models\AttributeGroup;

class AttributeGroupDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly AttributeGroup $group) {}
}
