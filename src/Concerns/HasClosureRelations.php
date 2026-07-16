<?php

namespace Jurager\Eav\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Relations\ClosureRelation;

trait HasClosureRelations
{
    /**
     * Define a relation whose results are resolved per-parent via a closure.
     *
     * @param  Closure(Model): (Builder|null)  $resolver
     */
    protected function closureRelation($related, Closure $resolver): ClosureRelation
    {
        $instance = $this->newRelatedInstance($related);

        return new ClosureRelation($instance->newQuery(), $this, $resolver);
    }
}
