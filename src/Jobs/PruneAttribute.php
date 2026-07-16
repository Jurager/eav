<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Eav;

class PruneAttribute implements ShouldQueue
{
    use Queueable;

    public function __construct(protected int $attributeId)
    {
    }

    public function handle(): void
    {
        Eav::$attributeModel::query()
            ->withTrashed()
            ->where('id', $this->attributeId)
            ->forceDelete();
    }
}
