<?php

namespace Jurager\Eav\Console;

use Jurager\Eav\Jobs\SyncFilterable;
use Laravel\Scout\Console\SyncIndexSettingsCommand as ScoutSyncIndexSettingsCommand;
use Laravel\Scout\EngineManager;

class SyncIndexSettingsCommand extends ScoutSyncIndexSettingsCommand
{
    public function handle(EngineManager $manager): void
    {
        parent::handle($manager);

        if (! class_exists(\Meilisearch\Client::class)) {
            return;
        }

        $attributeModel = config('eav.models.attribute');

        if (! $attributeModel) {
            return;
        }

        $attributeModel::withoutGlobalScopes()
            ->where('filterable', true)
            ->distinct()
            ->pluck('entity_type')
            ->each(fn (string $entityType) => SyncFilterable::dispatchSync($entityType));
    }
}
