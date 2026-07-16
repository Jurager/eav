<?php

namespace Jurager\Eav\Concerns;

use Illuminate\Support\Str;
use Jurager\Eav\Relations\BelongsToScoped;

trait HasScopedRelations
{
    /** Define a scoped belongs-to relationship. */
    protected function belongsToScoped(
        string $related,
        string $scopeKey,
        ?string $foreignKey = null,
        ?string $ownerKey = null,
        ?string $relation = null
    ): BelongsToScoped {
        $relation ??= $this->guessBelongsToRelation();

        $instance = $this->newRelatedInstance($related);

        $foreignKey ??= Str::snake($relation).'_'.$instance->getKeyName();
        $ownerKey ??= $instance->getKeyName();

        return new BelongsToScoped(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation,
            $scopeKey,
            $scopeKey
        );
    }
}