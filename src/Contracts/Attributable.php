<?php

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface Attributable
{
    /**
     * Entity type string used to scope attributes (e.g. 'product', 'category').
     * Must match the morph map key registered for this model.
     */
    public function getAttributeEntityType(): string;

    /**
     * Default filter parameters passed to getAvailableAttributesQuery().
     * Return [] for global scope (all entities share the same attribute set).
     * Return e.g. category IDs for byRelation scope.
     */
    public function getDefaultParameters(): array;

    /**
     * Builder that returns Attribute records available for this entity.
     * Called by AttributeManager to load the attribute schema.
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder;
}
