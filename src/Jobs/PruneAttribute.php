<?php

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Support\EavModels;

class PruneAttribute implements ShouldQueue
{
    use Queueable;

    public function __construct(protected int $attributeId)
    {
    }

    public function handle(): void
    {
        EavModels::query('attribute')->withTrashed()->find($this->attributeId)?->forceDelete();
    }
}
