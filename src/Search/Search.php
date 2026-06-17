<?php

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Facets\Facet;
use Jurager\Eav\Search\Facets\FacetContext;
use Jurager\Eav\Support\EavModels;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult as MeilisearchResult;

/**
 * Fluent builder for faceted search over indexed entities.
 */
class Search
{
    private string $entityType = '';

    private ?string $query = null;

    /** @var array<string, mixed> */
    private array $filter = [];

    /** @var array<string, string> */
    private array $fieldMap = [];

    /** @var Facet[] */
    private array $facets = [];

    private ?Indexes $index = null;

    public function __construct(
        private readonly FilterCompiler $compiler,
        private readonly FieldFactory $fieldFactory,
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

    /** @param Facet[] $facets */
    public function facets(array $facets): static
    {
        $this->facets = $facets;

        return $this;
    }

    /** @return SearchResult<Model> */
    public function search(int $perPage = 15, int $page = 1): SearchResult
    {
        $modelClass = Relation::getMorphedModel($this->entityType);

        if (! $modelClass || ! method_exists($modelClass, 'searchableAs')) {
            return new SearchResult([], 0, []);
        }

        $ctx = $this->context();
        $this->index = $this->meilisearch->index((new $modelClass())->searchableAs());

        $facetFields = collect($this->facets)
            ->flatMap(fn (Facet $facet) => $facet->facetFields($ctx))
            ->unique()
            ->values()
            ->all();

        $main = $this->index->search($this->query, array_filter([
            'filter' => $this->compiler->compile($this->filter, $this->resolver($ctx)),
            'facets' => $facetFields ?: null,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]));

        $facets = [];

        foreach ($this->facets as $facet) {
            $facets += $facet->collect($this, $main, $ctx);
        }

        return new SearchResult(
            ids: array_column($main->getHits(), 'id'),
            total: $main->getEstimatedTotalHits() ?? 0,
            facets: $this->group($facets),
        );
    }

    /** Whether a filter is active for the given key. */
    public function hasFilter(string $key): bool
    {
        return array_key_exists($key, $this->filter);
    }

    /** @param  string[]  $fields */
    public function facetOnlySearch(string $excludeKey, array $fields, FacetContext $ctx): MeilisearchResult
    {
        return $this->index->search($this->query, array_filter([
            'filter' => $this->compiler->compile($this->filter, $this->resolver($ctx, exclude: $excludeKey)),
            'facets' => $fields,
            'limit' => 0,
        ]));
    }

    /** Loads filterable attributes for the entity and wraps them with their field dependencies. */
    private function context(): FacetContext
    {
        $attributes = EavModels::query('attribute')
            ->forEntity($this->entityType)
            ->where('filterable', true)
            ->with('type')
            ->get();

        return new FacetContext($attributes, $this->fieldFactory, $this->localeRegistry->current());
    }

    /** @return \Closure(string): ?string */
    private function resolver(FacetContext $ctx, ?string $exclude = null): \Closure
    {
        return function (string $key) use ($ctx, $exclude): ?string {
            if ($exclude !== null && $key === $exclude) {
                return null;
            }

            foreach ($this->facets as $facet) {
                if (($field = $facet->field($key, $ctx)) !== null) {
                    return $field;
                }
            }

            return $this->fieldMap[$key] ?? null;
        };
    }

    /** @param  array<string, mixed>  $flat */
    private function group(array $flat): array
    {
        $result = [];

        foreach ($flat as $key => $value) {
            if (str_contains($key, '.')) {
                [$group, $sub] = explode('.', $key, 2);
                $result[$group][$sub] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
