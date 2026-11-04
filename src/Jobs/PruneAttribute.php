<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Eav;

/**
 * Permanently delete a soft-deleted attribute and its values.
 */
class PruneAttribute implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $attributeId)
    {
    }

    public function handle(): void
    {
        // Through the model, so the observer prunes the values before the row goes.
        Eav::$attributeModel::query()->onlyTrashed()->find($this->attributeId)?->forceDelete();
    }
}
