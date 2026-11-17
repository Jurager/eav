<?php

declare(strict_types=1);

namespace Jurager\Eav\Schema;

use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registries\AttributeRegistry;
use Jurager\Eav\Registries\SchemaRegistry;
use Jurager\Eav\Search\Index\Synchronizer;

/**
 * Synchronize cached schemas and search indexes with attribute definitions.
 */
class SchemaSynchronizer
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly AttributeRegistry $attributes,
        private readonly Synchronizer $indexes,
    ) {
    }

    /**
     * Refresh an attribute's cached schemas and the search indexes it is part of.
     *
     * @param  bool|null  $indexed  Whether the indexes are affected; null when the attribute being indexed decides.
     * @param  list<string>|null  $entityTypes  Entity types to refresh; those currently using the attribute when null.
     */
    public function refresh(Attribute $attribute, ?bool $indexed = null, ?array $entityTypes = null): void
    {
        $entityTypes ??= $this->entityTypes($attribute);

        $this->forget($entityTypes);
        $this->indexes->attribute($attribute, $entityTypes, $indexed);
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
}
