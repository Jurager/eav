<?php

declare(strict_types=1);

namespace Jurager\Eav\Listeners;

use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Search\Indexer;

/** Keeps the search index in step with attribute values, which change outside model events. */
class ReindexChangedEntities
{
    public function handle(EntityValuesChanged $event): void
    {
        Indexer::refresh($event->entityType)->withVariants($event->entityIds);
    }
}
