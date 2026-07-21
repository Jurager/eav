<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Facets;

use Jurager\Eav\Search\Search;
use Meilisearch\Search\SearchResult as MeilisearchResult;

/**
 * A facet declares which indexed fields to aggregate and how to read the
 * aggregation back from a Meilisearch response. Subclasses implement the
 * mechanics (term distribution, numeric range, …); {@see Search} only
 * orchestrates the queries and merges each facet's contribution.
 */
abstract class Facet
{
    /** Meilisearch field prefix EAV attribute values are indexed under. */
    public const string ATTRIBUTE_PREFIX = 'attributes.';

    protected bool $disjunctive = false;

    /**
     * Term-distribution facet over EAV attribute codes (with label enrichment).
     *
     * @param  string[]  $codes
     */
    public static function terms(array $codes): TermsFacet
    {
        return new TermsFacet($codes);
    }

    /**
     * Min/max range facet over numeric index fields (e.g. "prices.retail").
     *
     * @param  string[]  $fields
     */
    public static function range(array $fields): RangeFacet
    {
        return new RangeFacet($fields);
    }

    /**
     * Recompute this facet with its own active filter dropped, so its
     * counts/bounds reflect the full candidate set (multi-select, price slider).
     */
    public function disjunctive(bool $disjunctive = true): static
    {
        $this->disjunctive = $disjunctive;

        return $this;
    }

    /**
     * Index fields this facet requests in the search `facets` parameter.
     *
     * @return string[]
     */
    abstract public function facetFields(FacetContext $context): array;

    /** Map a filter key to its Meilisearch field, or null if this facet doesn't own it. */
    abstract public function field(string $key, FacetContext $context): ?string;

    /**
     * Pull this facet's contribution from the response as a flat [indexField => value]
     * map, running disjunctive corrections through $search when enabled.
     *
     * @return array<string, mixed>
     */
    abstract public function collect(Search $search, MeilisearchResult $main, FacetContext $context): array;
}
