<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

/** Exception thrown when attribute inheritance exceeds the maximum depth. */
class CircularInheritanceException extends EavException
{
    /** Create a new exception instance for max depth. */
    public static function maxDepthExceeded(string $model, array $unresolvedIds, int $maxDepth): self
    {
        return new self(sprintf(
            'Maximum attribute inheritance depth (%d) exceeded for [%s]. Unresolved IDs: [%s]. ' .
            'Check for circular dependencies or increase "eav.max_inheritance_depth".',
            $maxDepth,
            $model,
            implode(', ', $unresolvedIds)
        ));
    }
}
