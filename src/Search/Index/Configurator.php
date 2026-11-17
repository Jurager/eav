<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Jurager\Eav\Contracts\Search\UpdatesIndexSettings;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Search\SearchManager;

/**
 * Bring the engine's index settings in line with the index definitions.
 */
class Configurator
{
    public function __construct(
        private readonly IndexFactory $indexes,
        private readonly SearchManager $search,
    ) {}

    /**
     * Update the settings of an entity type's index. A type that is not searchable is skipped,
     * as is an engine that keeps no settings or is not installed.
     */
    public function configure(string $entityType): bool
    {
        try {
            $engine = $this->search->driver();
        } catch (SearchNotAvailableException) {
            // Entities may be indexed with Scout alone, with no engine set up to search them.
            return false;
        }

        if (! $engine instanceof UpdatesIndexSettings || $this->indexes->model($entityType) === null) {
            return false;
        }

        $engine->updateIndexSettings($this->indexes->for($entityType));

        return true;
    }

    /**
     * Update the settings of every searchable entity type.
     */
    public function all(): void
    {
        array_map($this->configure(...), $this->indexes->entityTypes());
    }
}
