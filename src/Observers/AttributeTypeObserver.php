<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Events\AttributeTypeCreated;
use Jurager\Eav\Events\AttributeTypeDeleted;
use Jurager\Eav\Events\AttributeTypeUpdated;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Registry\AttributeTypeRegistry;

class AttributeTypeObserver
{
    public function __construct(
        protected AttributeTypeRegistry $registry,
    ) {
    }

    /** Handle the "created" event. */
    public function created(AttributeType $type): void
    {
        $this->registry->forget();

        AttributeTypeCreated::dispatch($type);
    }

    /** Handle the "updated" event. */
    public function updated(AttributeType $type): void
    {
        $this->registry->forget();

        AttributeTypeUpdated::dispatch($type);
    }

    /** Handle the "deleted" event. */
    public function deleted(AttributeType $type): void
    {
        $this->registry->forget();

        AttributeTypeDeleted::dispatch($type);
    }
}
