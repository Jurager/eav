<?php

declare(strict_types=1);

namespace Jurager\Eav\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Eav\Search\Engine;
use Jurager\Eav\Search\Index\Indexer;
use Jurager\Eav\Search\Index\Synchronizer;

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
    public function handle(Engine $engine, Synchronizer $synchronizer): int
    {
        $types = $this->argument('type') !== null ? [(string) $this->argument('type')] : $synchronizer->entityTypes();

        foreach ($types as $type) {
            $indexer = Indexer::for($type);

            if ($this->option('flush')) {
                $indexer->flush();
            }

            $engine->configure($type);
            $indexer->all($this->option('chunk') !== null ? (int) $this->option('chunk') : null);

            $this->components->info("Search index rebuilt for [{$type}].");
        }

        if ($types === []) {
            $this->components->warn('No searchable entity types found.');
        }

        return self::SUCCESS;
    }
}
