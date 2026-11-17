<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Index\IndexField;

/**
 * An engine that searches the values of a facet by itself.
 *
 * Without it, the values are taken from the facet of a regular search and matched by substring.
 */
interface SearchesFacetValues
{
    /**
     * Values of a facet matching the text, within the search's query and its other filters.
     *
     * @return array<string, int> Counts keyed by value.
     *
     * @throws InvalidSearchQueryException
     */
    public function facetValues(Builder $search, IndexField $field, ?string $query): array;
}
