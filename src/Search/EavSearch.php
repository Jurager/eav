<?php

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Registry\FieldTypeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\EavModels;

/**
 * Faceted Meilisearch search over an EAV-indexed entity.
 *
 * Translates the same JSON:API `filter[...]` shape used elsewhere in the app into a
 * Meilisearch filter expression, runs the search with `facets`, and applies the
 * disjunctive-faceting pattern from Meilisearch docs (extra search per multi-select
 * facet, dropping that facet's filter to keep its counts accurate).
 *
 * Field resolution:
 *   — EAV attribute codes (filterable=true) → "attributes.{code}"
 *   — Non-EAV keys → must be listed in fieldMap(); otherwise silently dropped.
 *
 * Returns {@see EavSearchResult} with hit IDs, total, and enriched facets.
 * Call {@see EavSearchResult::paginate()} with the model class to hydrate models.
 *
 * Usage:
 *   EavSearch::for('product')
 *       ->query($q)
 *       ->filter($request->input('filter', []))
 *       ->fieldMap(['categories.category_id' => 'category_ids'])
 *       ->facets($facetCodes)
 *       ->disjunctive($facetCodes)
 *       ->search($perPage, $page)
 *       ->paginate(Product::class, $perPage, $page);
 */
class EavSearch
{
    private string $entityType;

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
        private FilterCompiler $compiler,
        private FieldTypeRegistry $fieldRegistry,
        private LocaleRegistry $localeRegistry,
    ) {
    }

    public static function for(string $entityType): static
    {
        $instance             = app(static::class);
        $instance->entityType = $entityType;

        return $instance;
    }

    public function query(?string $q): self
    {
        $this->query = $q;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    public function filter(array $filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    /**
     * @param  array<string, string>  $map  Filter key → indexed Meilisearch field.
     */
    public function fieldMap(array $map): self
    {
        $this->fieldMap = $map;

        return $this;
    }

    /**
     * @param  string[]  $codes  EAV attribute codes to compute facet distribution for.
     */
    public function facets(array $codes): self
    {
        $this->facetCodes = $codes;

        return $this;
    }

    /**
     * @param  string[]  $codes  Codes whose counts must stay unaffected by their own active filter.
     */
    public function disjunctive(array $codes): self
    {
        $this->disjunctiveCodes = $codes;

        return $this;
    }

    /**
     * @return EavSearchResult<Model>
     */
    public function search(int $perPage = 15, int $page = 1): EavSearchResult
    {
        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! method_exists($modelClass, 'searchableAs')) {
            return new EavSearchResult([], 0, []);
        }

        if (! class_exists(\Meilisearch\Client::class)) {
            return new EavSearchResult([], 0, []);
        }

        $eavAttrs = EavModels::query('attribute')
            ->forEntity($this->entityType)
            ->where('filterable', true)
            ->with('type')
            ->get();

        $eavCodes = $eavAttrs->pluck('code')->all();
        $resolve  = $this->buildFieldResolver($eavCodes);

        $index = app(\Meilisearch\Client::class)->index((new $modelClass())->searchableAs());

        $mainFilter = $this->compiler->compile($this->filter, $resolve);
        $facetKeys  = $this->resolveFacetKeys($this->facetCodes, $eavAttrs);

        $main = $index->search($this->query, array_filter([
            'filter' => $mainFilter,
            'facets' => $facetKeys ?: null,
            'limit'  => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]));

        $facets = $main->getFacetDistribution() ?? [];

        foreach ($this->disjunctiveCodes as $code) {
            if (! array_key_exists($code, $this->filter)) {
                // Disjunctive correction only matters when this facet is actively filtered.
                continue;
            }

            $altFilter      = $this->compiler->compile($this->filter, $this->buildFieldResolver($eavCodes, exclude: $code));
            $codeFacetKeys  = $this->resolveFacetKeys([$code], $eavAttrs);

            if (! $codeFacetKeys) {
                continue;
            }

            $alt = $index->search($this->query, array_filter([
                'filter' => $altFilter,
                'facets' => $codeFacetKeys,
                'limit'  => 0,
            ]));

            $altDist = $alt->getFacetDistribution() ?? [];

            foreach ($codeFacetKeys as $key) {
                if (isset($altDist[$key])) {
                    $facets[$key] = $altDist[$key];
                }
            }
        }

        return new EavSearchResult(
            ids: array_column($main->getHits(), 'id'),
            total: $main->getEstimatedTotalHits() ?? 0,
            facets: $this->enrichFacets($facets, $eavAttrs),
        );
    }

    /**
     * @param  string[]  $eavCodes
     * @return callable(string): ?string
     */
    private function buildFieldResolver(array $eavCodes, ?string $exclude = null): callable
    {
        return function (string $key) use ($eavCodes, $exclude): ?string {
            if ($exclude !== null && $key === $exclude) {
                return null;
            }

            if (in_array($key, $eavCodes, true)) {
                return "attributes.$key";
            }

            return $this->fieldMap[$key] ?? null;
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

                if (! $attr) {
                    return [];
                }

                return $this->fieldRegistry->make($attr)->filterableKeys();
            })
            ->map(fn ($key) => "attributes.$key")
            ->unique()
            ->values()
            ->all();
    }

    private function enrichFacets(array $facets, Collection $eavAttrs): array
    {
        $localeId = $this->localeRegistry->current();
        $byCode   = $eavAttrs->keyBy('code');
        $result   = [];

        foreach ($facets as $facetKey => $distribution) {
            $code  = str_replace('attributes.', '', $facetKey);
            $attr  = $byCode->get($code);
            $field = $attr ? $this->fieldRegistry->make($attr) : null;

            $result[$facetKey] = $field
                ? $field->enrichFacetDistribution($distribution, $localeId)
                : $distribution;
        }

        return $result;
    }

}
