<?php

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface Attributable
{
    /**
     * Entity type string used to scope attributes (e.g. 'product', 'category').
     * Must match the morph map key registered for this model.
     */
    public function attributeEntityType(): string;

    /**
     * IDs of the scope-model records that determine which attributes are available for this entity.
     * Return [] for a global (unscoped) attribute set.
     * Example: a Product returns its category IDs; a Category returns [].
     *
     * @return array<int>
     */
    public function attributeParameters(): array;

    /**
     * Whether this entity should inherit attributes from its parent.
     * Called by AttributeInheritanceResolver when resolving relation-scoped attributes.
     */
    public function shouldInheritAttributes(): bool;

    /**
     * Columns that must be selected when loading this entity for inheritance resolution.
     * Override to include any column that shouldInheritAttributes() reads from.
     *
     * @return array<string>
     */
    public function inheritanceScopeColumns(): array;

    /**
     * Builder that returns Attribute records available for this entity.
     * Called by AttributeManager to load the attribute schema.
     *
     * @param  array<int>  $params  Scope-model IDs from attributeParameters().
     */
    public function availableAttributesQuery(array $params = []): ?Builder;
}
