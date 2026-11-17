<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Eav;
use Jurager\Eav\Jobs\ReindexAttribute;
use Jurager\Eav\Jobs\ReindexEntities;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Search\Engine;

/**
 * Keep search indexes in step with the schema and the values: the one place that decides what to rebuild.
 */
class Synchronizer
{
    /**
     * Attribute columns deciding whether the attribute is indexed at all.
     */
    private const array FLAGS = ['searchable', 'filterable', 'sortable'];

    /**
     * Attribute columns that change what the index holds or how the engine treats it.
     */
    private const array INDEXED_COLUMNS = [...self::FLAGS, 'localizable', 'multiple', 'code', 'attribute_type_id', 'sort'];

    /**
     * Whether the attribute's last update changed what its indexes hold.
     */
    public function affectedBy(Attribute $attribute): bool
    {
        // A flipped flag means the attribute was indexed before the update, or is now.
        return $attribute->wasChanged(self::INDEXED_COLUMNS)
            && (self::indexed($attribute) || $attribute->wasChanged(self::FLAGS));
    }

    /**
     * An attribute was created, changed, deleted, restored, or shared with other entity types.
     *
     * @param  list<string>  $entityTypes  Entity types using the attribute, or having just stopped to.
     * @param  bool|null  $affected  Whether the indexes are affected; null when the attribute being indexed decides.
     */
    public function attribute(Attribute $attribute, array $entityTypes, ?bool $affected = null): void
    {
        if (! IndexFactory::available() || ! ($affected ?? self::indexed($attribute))) {
            return;
        }

        foreach (array_unique($entityTypes) as $entityType) {
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

        if (! IndexFactory::available() || $attribute === null || ! self::indexed($attribute)) {
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
     * The set of locales changed: localized fields gained, lost or renamed a slot.
     *
     * @param  bool  $documents  Whether existing documents hold values under a slot that is gone.
     */
    public function locales(bool $documents = false): void
    {
        if (! IndexFactory::available()) {
            return;
        }

        $indexes = app(IndexFactory::class);

        foreach ($this->entityTypes() as $entityType) {
            SyncIndexSettings::dispatch($entityType)->afterCommit();

            $localized = $indexes->for($entityType)->paths(static fn (IndexField $field): bool => $field->localized) !== [];

            if ($documents && $localized) {
                ReindexEntities::dispatch($entityType)->afterCommit();
            }
        }
    }

    /**
     * Push the settings of every searchable entity type to the engine, right away.
     */
    public function settings(): void
    {
        if (! IndexFactory::available()) {
            return;
        }

        $engine = app(Engine::class);

        foreach ($this->entityTypes() as $entityType) {
            $engine->configure($entityType);
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

    private static function indexed(Attribute $attribute): bool
    {
        return $attribute->searchable || $attribute->filterable || $attribute->sortable;
    }
}
