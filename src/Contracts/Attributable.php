<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface Attributable
{
    /** Get the entity type identifier. */
    public function getEntityType(): string;

    /** Get the scope IDs that determine available attributes. */
    public function attributeScopeIds(): array;

    /** Get the related entities whose attributes make up this entity's scope. */
    public function attributeScopeEntities(): Collection;

    /** Get the parent entity this one is a variant of, if any. */
    public function attributeParent(): ?Attributable;

    /** Determine if the entity is a variant of a parent entity. */
    public function isVariant(): bool;

    /** Determine if the entity inherits attributes from its parent. */
    public function shouldInheritAttributes(): bool;

    /** Get the columns required for inheritance resolution. */
    public function getInheritanceColumns(): array;

    /** Get the query builder for available attributes. */
    public function getAvailableAttributesQuery(array $scopes = []): ?Builder;
}
