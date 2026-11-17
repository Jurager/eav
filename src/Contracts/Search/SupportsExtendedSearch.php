<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Index\IndexField;

/**
 * A Scout engine that answers what Scout's query API cannot ask: groups of conditions, facets, searching
 * chosen fields or locales, highlighting, boosting, partitioning.
 *
 * Searches reach the engine already checked against the index definition.
 */
interface SupportsExtendedSearch
{
    /**
     * Facet data of the engine's raw results, keyed by the names the facets were asked by: a map of value
     * to count, or `min` and `max` for a numeric or date field.
     *
     * @return array<string, mixed>
     */
    public function facets(Builder $search, mixed $results): array;

    /**
     * Values of a facet matching the text, with their counts, within the search's query and other filters.
     *
     * @param  string  $name  The name the facet was asked by.
     * @return array<string, int>
     */
    public function facetValues(Builder $search, string $name, IndexField $field, ?string $query): array;
}
