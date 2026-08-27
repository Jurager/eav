<?php

declare(strict_types=1);

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
     * @template TRelatedModel of Model
     *
     * @param  class-string<TRelatedModel>  $related
     * @param  Closure(Model): (Builder<TRelatedModel>|null)  $resolver
     * @return ClosureRelation<TRelatedModel, $this>
     */
    protected function closureRelation(string $related, Closure $resolver): ClosureRelation
    {
        $instance = $this->newRelatedInstance($related);

        return new ClosureRelation($instance->newQuery(), $this, $resolver);
    }
}
