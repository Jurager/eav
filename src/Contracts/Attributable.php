<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface Attributable
{
    /** Get the entity type identifier. */
    public function getEntityType(): string;

    /** Get the scope IDs that determine available attributes. */
    public function attributeScopeIds(): array;

    /** Determine if the entity inherits attributes from its parent. */
    public function shouldInheritAttributes(): bool;

    /** Get the columns required for inheritance resolution. */
    public function getInheritanceColumns(): array;

    /** Get the query builder for available attributes. */
    public function getAvailableAttributesQuery(array $scopes = []): ?Builder;
}
