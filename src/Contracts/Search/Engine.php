<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Response;

/**
 * A search engine driver: answers a search builder in the engine's own terms.
 *
 * What an engine cannot do natively is covered by the package where it can be — see
 * FederatesSearches, SearchesFacetValues and UpdatesIndexSettings.
 */
interface Engine
{
    /**
     * Run a search and return a page of hits, the total, and the facets asked for.
     *
     * Facets are keyed by the asked name: a map of value to count, or `min` and `max` for a numeric or date
     * field. A facet the search filters on is counted without its own filter.
     *
     * @throws InvalidSearchQueryException When the search cannot be answered as asked.
     */
    public function search(Builder $search, int $perPage, int $page): Response;
}
