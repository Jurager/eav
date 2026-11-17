<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Search\Index\Indexer;

/**
 * Rebuild the search document of every entity of a type.
 */
class ReindexEntities implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $entityType,
    ) {}

    public function uniqueId(): string
    {
        return $this->entityType;
    }

    public function handle(Indexer $indexer): void
    {
        $indexer->all($this->entityType);
    }
}
