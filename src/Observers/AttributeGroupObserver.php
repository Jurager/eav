<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Registry\AttributeGroupRegistry;

/**
 * Drops cached groups whenever a group row changes. Domain events come from the Schema API.
 */
class AttributeGroupObserver
{
    public function __construct(
        protected AttributeGroupRegistry $registry,
    ) {
    }

    public function saved(AttributeGroup $group): void
    {
        $this->registry->forget();
    }

    public function deleted(AttributeGroup $group): void
    {
        $this->registry->forget();
    }
}
