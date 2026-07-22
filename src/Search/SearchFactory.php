<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

class SearchFactory
{
    /**
     * @param iterable<Contracts\FilterResolver> $resolvers
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly iterable $resolvers,
    ) {
    }

    /** Create search builder for entity type. */
    public function for(string $entityType): Builder
    {
        return new Builder($this->engine, $this->resolvers, $entityType);
    }
}