<?php

declare(strict_types=1);

namespace Jurager\Eav\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Eav\Search\Index\Configurator;
use Jurager\Eav\Search\Index\IndexFactory;

/**
 * Push search index settings to the engine.
 */
#[Signature('eav:sync-index-settings {type? : Entity type to sync; every searchable type when omitted}')]
#[Description('Sync search index settings — searchable, filterable and sortable fields, locales, typo tolerance.')]
class SyncIndexSettingsCommand extends Command
{
    public function handle(Configurator $configurator, IndexFactory $indexes): int
    {
        $types = $this->argument('type') !== null ? [(string) $this->argument('type')] : $indexes->entityTypes();

        foreach ($types as $type) {
            $configurator->configure($type)
                ? $this->components->info("Search index settings synced for [{$type}].")
                : $this->components->warn("Nothing to sync for [{$type}]: it is not searchable, or the engine keeps no index settings.");
        }

        if ($types === []) {
            $this->components->warn('No searchable entity types found.');
        }

        return self::SUCCESS;
    }
}
