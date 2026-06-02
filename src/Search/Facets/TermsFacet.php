<?php

namespace Jurager\Eav\Search\Facets;

use Jurager\Eav\Search\Search;
use Meilisearch\Search\SearchResult as MeilisearchResult;

/**
 * Facet that reports the value distribution of EAV attributes, with field-level
 * label enrichment (e.g. enum option labels). Indexed under "attributes.{code}".
 */
class TermsFacet extends Facet
{
    private const string PREFIX = 'attributes.';

    /**
     * @param  string[]  $codes  EAV attribute codes.
     */
    public function __construct(private readonly array $codes)
    {
    }

    public function facetFields(FacetContext $ctx): array
    {
        $fields = [];

        foreach ($this->codes as $code) {
            if ($attribute = $ctx->attribute($code)) {
                foreach ($ctx->field($attribute)->filterableKeys() as $key) {
                    $fields[] = self::PREFIX.$key;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    public function field(string $key, FacetContext $ctx): ?string
    {
        return in_array($key, $this->codes, true) ? self::PREFIX.$key : null;
    }

    public function collect(Search $search, MeilisearchResult $main, FacetContext $ctx): array
    {
        $result = [];

        foreach ($this->codes as $code) {
            $attribute = $ctx->attribute($code);

            if (! $attribute) {
                continue;
            }

            $field = $ctx->field($attribute);
            $fields = array_map(fn (string $key) => self::PREFIX.$key, $field->filterableKeys());

            $response = $this->disjunctive && $search->hasFilter($code)
                ? $search->facetOnlySearch($code, $fields, $ctx)
                : $main;

            $distribution = $response->getFacetDistribution() ?? [];

            foreach ($fields as $indexField) {
                $result[$indexField] = $field->enrichFacetDistribution($distribution[$indexField] ?? [], $ctx->localeId);
            }
        }

        return $result;
    }
}
