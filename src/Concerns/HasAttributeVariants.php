<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jurager\Eav\Contracts\Attributable;

/**
 * Read inherited values from an entity's parent.
 */
trait HasAttributeVariants
{
    /**
     * Return the parent relation name for variant entities, if configured.
     */
    protected function attributeParentRelationName(): ?string
    {
        return null;
    }

    /**
     * Resolve the parent entity from which this variant inherits attributes.
     */
    public function attributeParent(): ?Attributable
    {
        $relation = $this->attributeParentRelationName();

        if ($relation === null) {
            return null;
        }

        // Skip the query when the parent foreign key is absent.
        $foreignKey = $this->attributeParentRelation()?->getForeignKeyName();

        if ($foreignKey !== null && $this->getAttribute($foreignKey) === null) {
            return null;
        }

        $this->loadMissing($relation);

        return $this->{$relation} instanceof Attributable ? $this->{$relation} : null;
    }

    /**
     * Check whether the entity resolves to an attribute-inheritance parent.
     */
    public function isVariant(): bool
    {
        return $this->attributeParent() !== null;
    }

    /**
     * List the relations needed to read inherited attribute values without N+1 queries.
     *
     * @return list<string>
     */
    public function inheritedValueRelations(): array
    {
        $parent = $this->attributeParentRelationName();

        return $parent !== null
            ? ["{$parent}.attribute_values.attribute.type", "{$parent}.attribute_values.translations"]
            : [];
    }

    /**
     * Return the parent BelongsTo relation, or null if it is not defined.
     */
    public function attributeParentRelation(): ?BelongsTo
    {
        $relation = $this->attributeParentRelationName();
        $instance = $relation !== null && $this->isRelation($relation)
            ? $this->{$relation}()
            : null;

        return $instance instanceof BelongsTo ? $instance : null;
    }
}
