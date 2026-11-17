<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Search\Index\Indexer;

/**
 * Rebuild the search documents of every entity holding a value of an attribute.
 */
class ReindexAttribute implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $entityType,
        public readonly int|string $attributeId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->entityType}:{$this->attributeId}";
    }

    public function handle(Indexer $indexer): void
    {
        $indexer->attribute($this->entityType, $this->attributeId);
    }
}
