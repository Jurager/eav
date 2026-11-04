<?php

declare(strict_types=1);

namespace Jurager\Eav\Schema;

use Jurager\Eav\Jobs\SyncIndexSettings;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Search\Indexer;

/**
 * Synchronize cached schemas and search indexes with attribute definitions.
 */
class SchemaSynchronizer
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly AttributeRegistry $attributes,
    ) {
    }

    /**
     * Refresh an attribute's cached schemas and affected search indexes.
     */
    public function refresh(Attribute $attribute, ?bool $documents = null, ?bool $settings = null): void
    {
        $entityTypes = $this->entityTypes($attribute);

        $this->forget($entityTypes);
        $this->reindex(
            $attribute,
            $entityTypes,
            $documents ?? ($attribute->searchable || $attribute->filterable),
            $settings ?? (bool) $attribute->filterable,
        );
    }

    /**
     * Get current entity types, falling back to loaded types after deletion.
     *
     * @return list<string>
     */
    public function entityTypes(Attribute $attribute): array
    {
        return $attribute->exists
            ? $attribute->entityTypes()->pluck('entity_type')->all()
            : $attribute->applicableEntityTypes();
    }

    /**
     * Forget the cached schemas and definitions of the given entity types.
     *
     * @param  list<string>  $entityTypes
     */
    public function forget(array $entityTypes): void
    {
        foreach (array_unique($entityTypes) as $entityType) {
            $this->schemas->forget($entityType);
            $this->attributes->forget($entityType);
        }
    }

    /**
     * Queue document and filter settings updates for an attribute.
     *
     * @param  list<string>  $entityTypes
     */
    private function reindex(Attribute $attribute, array $entityTypes, bool $documents, bool $settings): void
    {
        if (! Indexer::available()) {
            return;
        }

        foreach ($entityTypes as $entityType) {
            if ($documents || $settings) {
                SyncSearchable::dispatch($entityType, $attribute->getKey())->afterCommit();
            }

            if ($settings) {
                SyncIndexSettings::dispatch($entityType)->afterCommit();
            }
        }
    }
}
