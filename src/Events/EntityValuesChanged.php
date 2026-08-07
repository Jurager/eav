<?php

declare(strict_types=1);

namespace Jurager\Eav\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Attribute values of one or more entities were written or dropped.
 */
class EntityValuesChanged
{
    use Dispatchable;

    /**
     * @param string $entityType Morph key of the entities.
     * @param array<int, int|string> $entityIds
     */
    public function __construct(
        public readonly string $entityType,
        public readonly array $entityIds,
    ) {
    }
}
