<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Jurager\Eav\Managers\AttributeManager;

interface Attributable
{
    /** Get cached attribute manager instance. */
    public function eav(): AttributeManager;

    /** Define Eloquent relation to entity_attribute values. */
    public function attributeValues(): MorphMany;

    /** Get the entity type identifier. */
    public function getEntityType(): string;

    /** Get the scope IDs that determine available attributes. */
    public function attributeScopeIds(): array;

    /** Get the related entities whose attributes make up this entity's scope. */
    public function attributeScopeEntities(): Collection;

    /** Determine if the entity's effective scope falls within the subtree rooted at any of the given IDs. */
    public function attributeScopeMatchesTree(array $rootIds): bool;

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
