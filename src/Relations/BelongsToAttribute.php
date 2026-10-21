<?php

declare(strict_types=1);

namespace Jurager\Eav\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jurager\Eav\Registry\AttributeRegistry;

/** Reuse reference metadata for lazy reads while retaining Eloquent's native eager loading. */
class BelongsToAttribute extends BelongsTo
{
    public function getResults()
    {
        if ($this->child->entity_type === null) {
            return parent::getResults();
        }

        $id = $this->getForeignKeyFrom($this->child);

        return ($id === null ? null : app(AttributeRegistry::class)->get($this->child->entity_type, (int) $id))
            ?? $this->getDefaultFor($this->parent);
    }
}
