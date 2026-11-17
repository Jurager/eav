<?php

declare(strict_types=1);

namespace Jurager\Eav\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Eav\Search\Index\Configurator;
use Jurager\Eav\Search\Index\Indexer;
use Jurager\Eav\Search\Index\IndexFactory;

/**
 * Rebuild search documents.
 */
#[Signature('eav:search:reindex
    {type? : Entity type to reindex; every searchable type when omitted}
    {--flush : Remove the existing documents first}
    {--chunk= : Number of entities per batch}')]
#[Description('Sync search index settings and rebuild the documents of an entity type.')]
class SearchReindexCommand extends Command
{
    public function handle(Configurator $configurator, Indexer $indexer, IndexFactory $indexes): int
    {
        $types = $this->argument('type') !== null ? [(string) $this->argument('type')] : $indexes->entityTypes();

        foreach ($types as $type) {
            if ($this->option('flush')) {
                $indexer->flush($type);
            }

            if (! $configurator->configure($type)) {
                $this->components->warn("[{$type}] is not searchable.");

                continue;
            }

            $indexer->all($type, $this->option('chunk') !== null ? (int) $this->option('chunk') : null);

            $this->components->info("Search index rebuilt for [{$type}].");
        }

        if ($types === []) {
            $this->components->warn('No searchable entity types found.');
        }

        return self::SUCCESS;
    }
}
