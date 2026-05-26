<?php

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Result of an {@see EavSearch} call: paginated hydrated models + facet distribution.
 *
 * @template TModel
 */
class EavSearchResult
{
    /**
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  array<string, array<string, int>>  $facets
     */
    public function __construct(
        public readonly LengthAwarePaginator $paginator,
        public readonly array $facets,
    ) {}
}
