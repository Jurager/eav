<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Search\Builder;

/**
 * A Scout engine that merges several searches into one ranked list by itself.
 *
 * Without it, the searches are run one by one and their hits merged by weight and rank.
 */
interface FederatesSearches
{
    /**
     * Run several searches as one.
     *
     * @param  list<Builder>  $searches
     * @return array{
     *     hits: list<array{0: Builder, 1: array<string, mixed>}>,
     *     total: int,
     *     facets: array<string, array<string, mixed>>,
     * } Hits as documents paired with the search each came from, in ranking order; facets by entity type.
     */
    public function federated(array $searches, int $limit, int $offset): array;
}
