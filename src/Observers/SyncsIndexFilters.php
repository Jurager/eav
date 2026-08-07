<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Jobs\SyncFilterable;

/**
 * Re-syncs an entity's filterable index paths when the rows they are built from come and go.
 */
class SyncsIndexFilters
{
    /**
     * Re-sync $entityType whenever rows of $source appear or disappear.
     *
     * @param class-string<Model> $source
     */
    public static function watch(string $source, string $entityType): void
    {
        $sync = static fn () => SyncFilterable::dispatch($entityType)->afterCommit();

        $source::created($sync);
        $source::deleted($sync);
    }
}
