<?php

declare(strict_types=1);

namespace Jurager\Eav\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Eav\Search\Engine;
use Jurager\Eav\Search\Index\Synchronizer;

/**
 * Push search index settings to the engine.
 */
#[Signature('eav:search:sync {type? : Entity type to sync; every searchable type when omitted}')]
#[Description('Sync search index settings — searchable, filterable and sortable fields, locales, typo tolerance.')]
class SearchSyncCommand extends Command
{
    public function handle(Engine $engine, Synchronizer $synchronizer): int
    {
        $types = $this->argument('type') !== null ? [(string) $this->argument('type')] : $synchronizer->entityTypes();

        foreach ($types as $type) {
            $engine->configure($type);

            $this->components->info("Search index settings synced for [{$type}].");
        }

        if ($types === []) {
            $this->components->warn('No searchable entity types found.');
        }

        return self::SUCCESS;
    }
}
