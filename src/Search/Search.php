<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Contracts\FilterResolver;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Jurager\Eav\Search\Facets\Facet;
use Jurager\Eav\Search\Facets\FacetContext;
use Jurager\Eav\Eav;
use Jurager\Filterable\Parsing\FilterParser;
use Jurager\Filterable\Support\ParsedFilters;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult as MeilisearchResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class Search
{
    private string $entityType = '';

    private ?string $query = null;

    private ParsedFilters $filter;

    /** @var array<string, string> */
    private array $map = [];

    /** @var array<int, Facet> */
    private array $facets = [];

    private ?Indexes $index = null;

    private ?Model $model = null;

    public function __construct(
        private readonly MeilisearchFilterCompiler $compiler,
        private readonly FieldFactory $fieldFactory,
        private readonly LocaleRegistry $localeRegistry,
        private readonly Client $meilisearch,
        private readonly LoggerInterface $logger,
    ) {
        $this->filter = (new FilterParser())->parse([], []);
    }

    /** Create search instance for entity type. */
    public static function for(string $entityType): static
    {
        $instance = app(static::class);
        $instance->entityType = $entityType;

        $modelClass = Relation::getMorphedModel($entityType);
        $instance->model = $modelClass ? new $modelClass() : null;

        return $instance;
    }

    /** Set search query string. */
    public function query(?string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** Parse and resolve filters. */
    public function filter(array $filter): static
    {
        $parsed = (new FilterParser())->parse($filter, []);

        if ($this->model !== null) {
            $parsed = $parsed->withSanitized(
                filters:   $this->resolveFilters($parsed->filters, $this->model),
                orGroups:  $parsed->orGroups,
                andGroups: $parsed->andGroups,
            );
        }

        $this->filter = $parsed;

        return $this;
    }

    /** Resolve filters unhandled by search index via tagged resolvers. */
    private function resolveFilters(array $filters, Model $model): array
    {
        $resolvers = null;
        $result = [];

        foreach ($filters as $key => $value) {
            $key = (string) $key;

            if (! str_contains($key, '.') || $this->directlyIndexed($key, $model)) {
                $result[$key] = $value;
                continue;
            }

            $resolvers ??= [...app()->tagged('eav.search.resolvers')];
            $resolved = null;

            foreach ($resolvers as $resolver) {
                /** @var FilterResolver $resolver */
                if (($resolved = $resolver->resolve($key, $value, $model)) !== null) {
                    break;
                }
            }

            [$resolvedKey, $resolvedValue] = $resolved ?? [$key, $value];
            $result[$resolvedKey] = $resolvedValue;
        }

        return $result;
    }

    /** Check if filter key is directly indexed. */
    private function directlyIndexed(string $key, Model $model): bool
    {
        return $model instanceof InteractsWithIndex && array_key_exists($key, $model->indexed());
    }

    /** Check if filter is active for key. */
    public function hasFilter(string $key): bool
    {
        return array_key_exists($key, $this->filter->filters);
    }

    /** Read numeric IDs from filter. */
    public function ids(string $key): array
    {
        $value = $this->filter->filters[$key] ?? null;

        if (is_array($value)) {
            $value = $value['in'] ?? $value['eq'] ?? $value;
        }

        if (blank($value)) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map('intval', $items),
            static fn ($id): bool => $id > 0
        ));
    }

    /** Set filter keys mapping. */
    public function map(array $map): static
    {
        $this->map = $map;

        return $this;
    }

    /** Set facets for computation. */
    public function facets(array $facets): static
    {
        $this->facets = $facets;

        return $this;
    }

    /** Execute search. */
    public function search(int $perPage = 15, int $page = 1): SearchResult
    {
        if (! $this->model || ! method_exists($this->model, 'searchableAs')) {
            return new SearchResult([], 0, []);
        }

        $context = $this->context();
        $this->index = $this->meilisearch->index($this->model->searchableAs());

        $resolve = $this->resolver($context);

        $this->logUnresolved($this->compiler->unresolved($this->filter, $resolve));

        try {
            $main = $this->index->search($this->query, array_filter([
                'filter' => $this->compiler->compile($this->filter, $resolve),
                'facets' => $this->facetFields($context) ?: null,
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
            ]));
        } catch (ApiException $e) {
            throw new BadRequestHttpException("Invalid search request: {$e->message}", $e);
        }

        return new SearchResult(
            ids: array_column($main->getHits(), 'id'),
            total: $main->getEstimatedTotalHits() ?? 0,
            facets: $this->group($this->collectFacets($main, $context)),
            context: $context,
        );
    }

    /** Get required Meilisearch facet fields. */
    private function facetFields(FacetContext $context): array
    {
        $fields = [];

        foreach ($this->facets as $facet) {
            foreach ($facet->facetFields($context) as $field) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /** Collect facets from search result. */
    private function collectFacets(MeilisearchResult $main, FacetContext $context): array
    {
        $facets = [];

        foreach ($this->facets as $facet) {
            foreach ($facet->collect($this, $main, $context) as $key => $value) {
                $facets[$key] = $value;
            }
        }

        return $facets;
    }

    /** Log unresolved filter keys. */
    private function logUnresolved(array $unresolved): void
    {
        foreach (array_keys($unresolved) as $key) {
            $this->logger->warning("eav.search: filter key [{$key}] has no indexed field, condition dropped", [
                'entity_type' => $this->entityType,
            ]);
        }
    }

    /** Perform facet-only search. */
    public function facetOnlySearch(string $excludeKey, array $fields, FacetContext $context): MeilisearchResult
    {
        try {
            return $this->index->search($this->query, array_filter([
                'filter' => $this->compiler->compile($this->filter, $this->resolver($context, exclude: $excludeKey)),
                'facets' => $fields,
                'limit'  => 0,
            ]));
        } catch (ApiException $e) {
            throw new BadRequestHttpException("Invalid search request: {$e->message}", $e);
        }
    }

    /** Load filterable attributes context. */
    private function context(): FacetContext
    {
        $attributes = Eav::$attributeModel::query()
            ->forEntity($this->entityType)
            ->where('filterable', true)
            ->with('type')
            ->get();

        return new FacetContext($attributes, $this->fieldFactory, $this->localeRegistry->current());
    }

    /** Create field resolver closure. */
    private function resolver(FacetContext $context, ?string $exclude = null): \Closure
    {
        return function (string $key) use ($context, $exclude): ?string {
            if ($exclude !== null && $key === $exclude) {
                return null;
            }

            if ($key === 'id') {
                return 'id';
            }

            foreach ($this->facets as $facet) {
                if (($field = $facet->field($key, $context)) !== null) {
                    return $field;
                }
            }

            if ($this->model instanceof InteractsWithIndex && ($field = $this->model->indexed()[$key] ?? null) !== null) {
                return $field;
            }

            // Any attribute flagged filterable for this entity type is a valid filter
            // key even when it isn't among the facets requested for *this* call — facet
            // selection (category/site-scoped) and filter eligibility are separate concerns.
            if ($context->attribute($key) !== null) {
                return Facet::ATTRIBUTE_PREFIX.$key;
            }

            return $this->map[$key] ?? null;
        };
    }

    /** Group flat facet keys into nested structures. */
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
