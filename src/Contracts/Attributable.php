<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface Attributable
{
    /** Get the EAV entity type identifier. */
    public function getEavEntityType(): string;

    /** Get the scope IDs that determine available attributes. */
    public function getEavScopes(): array;

    /** Determine if the entity inherits attributes from its parent. */
    public function shouldInheritEavAttributes(): bool;

    /** Get the columns required for inheritance resolution. */
    public function getEavInheritanceColumns(): array;

    /** Get the query builder for available attributes. */
    public function getAvailableAttributesQuery(array $scopes = []): ?Builder;
}
