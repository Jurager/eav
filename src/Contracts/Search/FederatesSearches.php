<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Response;

/**
 * An engine that merges several searches into one ranked list by itself.
 *
 * Without it, the searches are run one by one and their hits merged by weighted score.
 */
interface FederatesSearches
{
    /**
     * Run several searches as one. Facets are keyed by entity type, then by the asked name.
     *
     * @param  list<Builder>  $searches
     *
     * @throws InvalidSearchQueryException
     */
    public function federated(array $searches, int $limit, int $offset): Response;
}
