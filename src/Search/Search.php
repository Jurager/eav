<?php

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\EavModels;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

/**
 * Fluent builder for faceted search over an indexed entity.
 *
 * Translates JSON:API `filter[...]` into a search filter expression, runs the
 * search with facets, and applies disjunctive-faceting (extra search per active
 * multi-select facet to keep counts accurate regardless of that facet's own filter).
 *
 * Usage:
 *   Search::for('product')
 *       ->query($q)
 *       ->filter($filter)
 *       ->fieldMap(['categories.category_id' => 'category_ids'])
 *       ->facets($facetCodes)
 *       ->disjunctive($facetCodes)
 *       ->search($perPage, $page)
 *       ->paginate(Product::class, $perPage, $page);
 */
class Search
{
    private const string ATTRIBUTES_PREFIX = 'attributes.';

    private string $entityType = '';

    private ?string $query = null;

    /** @var array<string, mixed> */
    private array $filter = [];

    /** @var array<string, string> */
    private array $fieldMap = [];

    /** @var string[] */
    private array $facetCodes = [];

    /** @var string[] */
    private array $disjunctiveCodes = [];

    public function __construct(
        private readonly FilterCompiler $compiler,
        private readonly FieldTypeRegistry $fieldRegistry,
        private readonly LocaleRegistry $localeRegistry,
        private readonly Client $meilisearch,
    ) {
    }

    public static function for(string $entityType): static
    {
        $instance = app(static::class);
        $instance->entityType = $entityType;

        return $instance;
    }

    public function query(?string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** @param array<string, mixed> $filter */
    public function filter(array $filter): static
    {
        $this->filter = $filter;

        return $this;
    }

    /** @param array<string, string> $map  Filter key → indexed Meilisearch field. */
    public function fieldMap(array $map): static
    {
        $this->fieldMap = $map;

        return $this;
    }

    /** @param string[] $codes  EAV attribute codes to compute facet distribution for. */
    public function facets(array $codes): static
    {
        $this->facetCodes = $codes;

        return $this;
    }

    /** @param string[] $codes  Codes whose counts must stay unaffected by their own active filter. */
    public function disjunctive(array $codes): static
    {
        $this->disjunctiveCodes = $codes;

        return $this;
    }

    /** @return SearchResult<Model> */
    public function search(int $perPage = 15, int $page = 1): SearchResult
    {
        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! method_exists($modelClass, 'searchableAs')) {
            return new SearchResult([], 0, []);
        }

        $eavAttrs = EavModels::query('attribute')
            ->forEntity($this->entityType)
            ->where('filterable', true)
            ->with('type')
            ->get();

        $eavCodes = $eavAttrs->pluck('code')->all();
        $indexName = (new $modelClass())->searchableAs();
        $index = $this->meilisearch->index($indexName);

        $mainFilter = $this->compiler->compile($this->filter, $this->fieldResolver($eavCodes));
        $facetKeys = $this->resolveFacetKeys($this->facetCodes, $eavAttrs);

        $main = $index->search($this->query, array_filter([
            'filter' => $mainFilter,
            'facets' => $facetKeys ?: null,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]));

        $facets = $this->applyDisjunctiveCorrections(
            $index,
            $eavAttrs,
            $eavCodes,
            $main->getFacetDistribution() ?? [],
        );

        return new SearchResult(
            ids: array_column($main->getHits(), 'id'),
            total: $main->getEstimatedTotalHits() ?? 0,
            facets: $this->enrichFacets($facets, $eavAttrs),
        );
    }

    /**
     * Runs a facet-only search for each active disjunctive facet, dropping that
     * facet's own filter so its distribution reflects the full candidate set.
     *
     * @param  Indexes  $index
     * @param  Collection<int, Model>  $eavAttrs
     * @param  string[]  $eavCodes
     * @param  array<string, array<string, int>>  $facets
     * @return array<string, array<string, int>>
     */
    private function applyDisjunctiveCorrections(
        mixed $index,
        Collection $eavAttrs,
        array $eavCodes,
        array $facets,
    ): array {
        foreach ($this->disjunctiveCodes as $code) {
            if (! array_key_exists($code, $this->filter)) {
                continue;
            }

            $facetKeys = $this->resolveFacetKeys([$code], $eavAttrs);

            if (! $facetKeys) {
                continue;
            }

            $altFilter = $this->compiler->compile(
                $this->filter,
                $this->fieldResolver($eavCodes, exclude: $code),
            );

            $alt = $index->search($this->query, array_filter([
                'filter' => $altFilter,
                'facets' => $facetKeys,
                'limit' => 0,
            ]));
            $altDist = $alt->getFacetDistribution() ?? [];

            foreach ($facetKeys as $key) {
                if (isset($altDist[$key])) {
                    $facets[$key] = $altDist[$key];
                }
            }
        }

        return $facets;
    }

    /**
     * Returns a closure that maps a filter key to its Meilisearch field name.
     * EAV attribute codes resolve to `attributes.{code}`; other keys use fieldMap().
     *
     * @param  string[]  $eavCodes
     * @return \Closure(string): ?string
     */
    private function fieldResolver(array $eavCodes, ?string $exclude = null): \Closure
    {
        return function (string $key) use ($eavCodes, $exclude): ?string {
            if ($exclude !== null && $key === $exclude) {
                return null;
            }

            return in_array($key, $eavCodes, true)
                ? self::ATTRIBUTES_PREFIX.$key
                : ($this->fieldMap[$key] ?? null);
        };
    }

    /**
     * @param  string[]  $codes
     * @param  Collection<int, Model>  $eavAttrs
     * @return string[]
     */
    private function resolveFacetKeys(array $codes, Collection $eavAttrs): array
    {
        $byCode = $eavAttrs->keyBy('code');

        return collect($codes)
            ->flatMap(function (string $code) use ($byCode): array {
                $attr = $byCode->get($code);

                return $attr ? $this->fieldRegistry->make($attr)->filterableKeys() : [];
            })
            ->map(fn (string $key) => self::ATTRIBUTES_PREFIX.$key)
            ->unique()
            ->values()
            ->all();
    }

    /** @param Collection<int, Model> $eavAttrs */
    private function enrichFacets(array $facets, Collection $eavAttrs): array
    {
        $localeId = $this->localeRegistry->current();
        $byCode = $eavAttrs->keyBy('code');
        $result = [];

        foreach ($facets as $facetKey => $distribution) {
            $code = substr($facetKey, strlen(self::ATTRIBUTES_PREFIX));
            $attr = $byCode->get($code);
            $field = $attr ? $this->fieldRegistry->make($attr) : null;

            $result[$facetKey] = $field
                ? $field->enrichFacetDistribution($distribution, $localeId)
                : $distribution;
        }

        return $result;
    }
}
