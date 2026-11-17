<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Index;

use Jurager\Eav\Jobs\ReindexAttribute;
use Jurager\Eav\Jobs\ReindexEntities;
use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;

/**
 * Keep search indexes in step with the schema and the values: the one place that decides what to rebuild.
 */
class Synchronizer
{
    /**
     * Attribute columns deciding whether the attribute is indexed at all.
     */
    public const array FLAGS = ['searchable', 'filterable', 'sortable'];

    /**
     * Attribute columns that change what the index holds or how the engine treats it.
     */
    private const array INDEXED_COLUMNS = [...self::FLAGS, 'localizable', 'multiple', 'code', 'attribute_type_id', 'sort'];

    public function __construct(
        private readonly IndexFactory $indexes,
        private readonly Indexer $indexer,
    ) {}

    /**
     * Whether the search index holds the attribute.
     */
    public static function indexed(Attribute $attribute): bool
    {
        return $attribute->searchable || $attribute->filterable || $attribute->sortable;
    }

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
            $this->indexer->withVariants($entityType, $entityIds);
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

        foreach ($this->indexes->entityTypes() as $entityType) {
            SyncIndexSettings::dispatch($entityType)->afterCommit();

            if ($documents && $this->indexes->for($entityType)->paths(static fn (IndexField $field): bool => $field->localized) !== []) {
                ReindexEntities::dispatch($entityType)->afterCommit();
            }
        }
    }
}
