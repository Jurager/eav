<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Jurager\Eav\Search\Contracts\Transport;

/**
 * Push the derived settings of search indexes to the engine.
 */
class Configurator
{
    public function __construct(
        private readonly IndexFactory $indexes,
        private readonly Settings $settings,
        private readonly Transport $transport,
    ) {}

    /**
     * Push the settings of an entity type's index; a type that is not searchable is skipped.
     */
    public function configure(string $entityType): bool
    {
        if ($this->indexes->model($entityType) === null) {
            return false;
        }

        $index = $this->indexes->for($entityType);

        $this->transport->updateSettings($index->uid(), $this->settings->for($index));

        return true;
    }

    /**
     * Push the settings of every searchable entity type.
     */
    public function all(): void
    {
        array_map($this->configure(...), $this->indexes->entityTypes());
    }
}
