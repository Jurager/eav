<?php

declare(strict_types=1);

namespace Jurager\Eav\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Eav;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;

/**
 * Restore attribute index settings after Scout synchronizes its configuration.
 */
class RestoreIndexSettings
{
    public function handle(CommandFinished $event): void
    {
        if ($event->command !== 'scout:sync-index-settings' || $event->exitCode !== 0) {
            return;
        }

        foreach ($this->entityTypes() as $entityType) {
            SyncIndexSettings::dispatchSync($entityType);
        }
    }

    /**
     * Entity types with attributes, and those of models declaring index paths of their own.
     *
     * @return list<string>
     */
    private function entityTypes(): array
    {
        $withAttributes = Eav::$attributeEntityTypeModel::query()->distinct()->pluck('entity_type')->all();

        $withIndexPaths = array_keys(array_filter(
            Relation::morphMap(),
            static fn (mixed $model): bool => is_string($model) && is_subclass_of($model, InteractsWithIndex::class),
        ));

        return array_values(array_unique([...$withAttributes, ...$withIndexPaths]));
    }
}
