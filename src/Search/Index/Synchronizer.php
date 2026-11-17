<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Eav;
use Jurager\Eav\Jobs\ReindexAttribute;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Search\Contracts\Transport;

/**
 * Keep search indexes in step with the schema and the values: the one place that decides what to rebuild.
 */
class Synchronizer
{
    /**
     * Attribute columns that change what the index holds or how the engine treats it.
     */
    public const array INDEXED_COLUMNS = ['searchable', 'filterable', 'sortable', 'localizable', 'multiple', 'code', 'attribute_type_id', 'sort'];

    /**
     * An attribute was created, changed, deleted or restored.
     *
     * @param  list<string>  $entityTypes
     * @param  bool|null  $changed  Whether anything the index depends on changed; null to decide from the flags.
     */
    public function attribute(Attribute $attribute, array $entityTypes, ?bool $changed = null): void
    {
        $indexed = $attribute->searchable || $attribute->filterable || $attribute->sortable;

        if (! IndexFactory::available() || ! ($changed ?? $indexed)) {
            return;
        }

        foreach ($entityTypes as $entityType) {
            SyncIndexSettings::dispatch($entityType)->afterCommit();
            ReindexAttribute::dispatch($entityType, $attribute->getKey())->afterCommit();
        }
    }

    /**
     * A select option was saved or deleted: its code and labels are part of the documents.
     */
    public function enum(AttributeEnum $enum): void
    {
        $attribute = $enum->attribute;

        if (! IndexFactory::available() || $attribute === null || ! ($attribute->searchable || $attribute->filterable || $attribute->sortable)) {
            return;
        }

        foreach ($attribute->applicableEntityTypes() as $entityType) {
            ReindexAttribute::dispatch($entityType, $attribute->getKey())->afterCommit();
        }
    }

    /**
     * Attribute values of some entities were written or dropped.
     *
     * @param  array<int, int|string>  $entityIds
     */
    public function values(string $entityType, array $entityIds): void
    {
        if (IndexFactory::available()) {
            Indexer::for($entityType)->withVariants($entityIds);
        }
    }

    /**
     * The set of locales changed: localized fields gain or lose a slot.
     */
    public function locales(): void
    {
        $this->settings();
    }

    /**
     * Sync the settings of every searchable entity type, queued or right away.
     */
    public function settings(bool $now = false): void
    {
        if (! IndexFactory::available()) {
            return;
        }

        foreach ($this->entityTypes() as $entityType) {
            $now
                ? app(Settings::class)->sync($entityType, app(Transport::class))
                : SyncIndexSettings::dispatch($entityType)->afterCommit();
        }
    }

    /**
     * Entity types with a search index: searchable models in the morph map, and those holding attributes.
     *
     * @return list<string>
     */
    public function entityTypes(): array
    {
        $types = [
            ...array_keys(Relation::morphMap()),
            ...Eav::$attributeEntityTypeModel::query()->distinct()->pluck('entity_type')->all(),
        ];

        $indexes = app(IndexFactory::class);

        return array_values(array_filter(
            array_unique($types),
            static fn (string $type): bool => $indexes->model($type) !== null,
        ));
    }
}
