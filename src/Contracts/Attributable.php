<?php

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * Whether this entity should inherit attributes from its parent.
     * Called by AttributeInheritanceResolver when resolving byRelation scope.
     */
    public function shouldInheritAttributes(): bool;

    /**
     * Builder that returns Attribute records available for this entity.
     * Called by AttributeManager to load the attribute schema.
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder;

    /**
     * Relation that provides available attributes for other entities scoped by this model.
     * Return null if this model does not act as an attribute scope provider.
     */
    public function available_attributes(): ?BelongsToMany;
}
