<?php

namespace Jurager\Eav\Exceptions;

class CircularInheritanceException extends EavException
{
    /** @param array<int|string> $remainingIds */
    public static function maxDepthExceeded(string $model, array $remainingIds, int $maxDepth): self
    {
        $ids = implode(', ', $remainingIds);

        return new self(
            "Failed to resolve attribute inheritance for [{$model}] within the configured depth limit ({$maxDepth}). "
            ."The following parent IDs remain unresolved: [{$ids}]. "
            .'This usually indicates a circular inheritance chain or an unexpectedly deep hierarchy. '
            .'Review the parent_id relationships or adjust eav.max_inheritance_depth.'
        );
    }
}
