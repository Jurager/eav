<?php

namespace Jurager\Eav\Search\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a filter key couldn't be resolved to an indexed Meilisearch field
 * and its condition was dropped. No listener is required — this is a safety
 * net for observability, e.g. logging a genuinely unrecognized filter key.
 */
class FilterKeyUnresolved
{
    use Dispatchable;

    public function __construct(
        public readonly string $entityType,
        public readonly string $key,
        public readonly mixed $value,
    ) {
    }
}
