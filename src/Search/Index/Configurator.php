<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Search\SearchManager;
use Laravel\Scout\Contracts\UpdatesIndexSettings;

/**
 * Bring an engine's index settings in line with the index definitions, through Scout.
 */
class Configurator
{
    public function __construct(
        private readonly IndexFactory $indexes,
        private readonly SearchManager $search,
        private readonly Repository $config,
    ) {}

    /**
     * Update the settings of an entity type's index. A type that is not searchable is skipped, as is an
     * engine that keeps no settings or has none derived for it.
     */
    public function configure(string $entityType): bool
    {
        if (($model = $this->indexes->model($entityType)) === null) {
            return false;
        }

        $engine = $model->searchableUsing();
        $index = $this->indexes->for($model);
        $settings = $this->search->dialect($engine)->settings($index);

        if ($settings === null || ! $engine instanceof UpdatesIndexSettings) {
            return false;
        }

        if ($this->config->get('scout.soft_delete', false) && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $settings = $engine->configureSoftDeleteFilter($settings);
        }

        $engine->updateIndexSettings($index->uid(), $settings);

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
