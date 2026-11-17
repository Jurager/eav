<?php

declare(strict_types=1);

namespace Jurager\Eav\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Eav\Search\Contracts\Transport;
use Jurager\Eav\Search\Index\Settings;

/**
 * Push the settings of an entity type's search index to the engine.
 */
class SyncIndexSettings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $entityType,
    ) {}

    public function uniqueId(): string
    {
        return $this->entityType;
    }

    public function handle(Settings $settings, Transport $transport): void
    {
        $settings->sync($this->entityType, $transport);
    }
}
