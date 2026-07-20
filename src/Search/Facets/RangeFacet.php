<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Facets;

use Jurager\Eav\Search\Search;
use Meilisearch\Search\SearchResult as MeilisearchResult;

/**
 * Facet that reports the {min, max} bounds of numeric index fields via
 * Meilisearch facetStats — e.g. a price slider per price type ("prices.{code}").
 * The same fields become filterable by range (gte/lte/between).
 */
class RangeFacet extends Facet
{
    /**
     * @param  string[]  $fields  Numeric index fields, e.g. ["prices.retail"].
     */
    public function __construct(private readonly array $fields)
    {
    }

    public function facetFields(FacetContext $context): array
    {
        return $this->fields;
    }

    public function field(string $key, FacetContext $context): ?string
    {
        return in_array($key, $this->fields, true) ? $key : null;
    }

    public function collect(Search $search, MeilisearchResult $main, FacetContext $context): array
    {
        $result = [];

        foreach ($this->fields as $field) {
            $response = $this->disjunctive && $search->hasFilter($field)
                ? $search->facetOnlySearch($field, [$field], $context)
                : $main;

            $stats = $response->getFacetStats();

            if (isset($stats[$field])) {
                $result[$field] = [
                    'min' => $stats[$field]['min'] ?? null,
                    'max' => $stats[$field]['max'] ?? null,
                ];
            }
        }

        return $result;
    }
}
