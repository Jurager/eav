<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Jurager\Eav\Relations\BelongsToScoped;

trait HasScopedRelations
{
    /** Define a scoped, inverse one-to-one or many relationship. */
    protected function belongsToScoped(
        string $related,
        string $foreignKey,
        string $foreignScopeKey,
        ?string $ownerScopeKey = null,
        ?string $ownerKey = null,
        ?string $relation = null
    ): BelongsToScoped {
        $relation ??= $this->guessBelongsToRelation();

        $instance = $this->newRelatedInstance($related);

        $ownerKey ??= $instance->getKeyName();

        return new BelongsToScoped(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation,
            $foreignScopeKey,
            $ownerScopeKey ?? $foreignScopeKey
        );
    }
}
