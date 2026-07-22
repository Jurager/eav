<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult as MeilisearchResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class Engine
{
    public function __construct(
        private readonly Client $meilisearch,
        private readonly LoggerInterface $logger,
        private readonly FieldFactory $fieldFactory,
        private readonly LocaleRegistry $localeRegistry,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly Compiler $compiler,
    ) {
    }

    /** Execute search query. */
    public function search(Builder $builder, int $limit = 15, int $page = 1): Result
    {
        $model = $builder->getModel();

        if (! $model || ! method_exists($model, 'searchableAs')) {
            return new Result([], 0, []);
        }

        $indexUid = $model->searchableAs();
        $resolver = $this->createResolver($builder);
        $filter   = $builder->getFilter();

        $this->logUnresolved($this->compiler->unresolved($filter, $resolver), $builder->getEntityType());

        $requests = $this->buildSearchRequests($builder, $indexUid, $resolver, $limit, $page);
        $keys = array_keys($requests);

        try {
            $response = $this->meilisearch->multiSearch(array_values($requests));
        } catch (ApiException $e) {
            throw new BadRequestHttpException("Invalid search request: {$e->getMessage()}", $e);
        }

        $results = [];

        foreach ($response['results'] as $index => $res) {
            $results[$keys[$index]] = new MeilisearchResult($res);
        }

        $main = $results['main'];

        return new Result(
            ids: array_column($main->getHits(), 'id'),
            total: $main->getEstimatedTotalHits() ?? 0,
            facets: Arr::undot($this->hydrateFacets($results, $builder))
        );
    }

    /** Build Meilisearch multi-search requests. */
    private function buildSearchRequests(Builder $builder, string $uid, Closure $resolver, int $limit, int $page): array
    {
        $facetFields = [];

        foreach ($builder->getFacets() as $facet) {
            $facetFields[] = $this->formatFacetField($facet);
        }

        $mainRequest = [
            'indexUid' => $uid,
            'limit'    => $limit,
            'offset'   => ($page - 1) * $limit,
        ];

        if (($query = $builder->getQuery()) !== null) {
            $mainRequest['q'] = $query;
        }

        if (($filter = $this->compiler->compile($builder->getFilter(), $resolver)) !== null) {
            $mainRequest['filter'] = $filter;
        }

        if (! empty($facetFields)) {
            $mainRequest['facets'] = $facetFields;
        }

        $requests = ['main' => $mainRequest];

        foreach ($builder->getFacets() as $key) {
            if ($builder->hasFilter($key)) {
                $excludeResolver = $this->createResolver($builder, $key);

                $requests["facet_{$key}"] = [
                    'indexUid' => $uid,
                    'q'        => $builder->getQuery(),
                    'filter'   => $this->compiler->compile($builder->getFilter(), $excludeResolver),
                    'facets'   => [$this->formatFacetField($key)],
                    'limit'    => 0,
                ];
            }
        }

        return $requests;
    }

    /** Create field resolver closure. */
    private function createResolver(Builder $builder, ?string $exclude = null): Closure
    {
        $map = $builder->getMap();
        $model = $builder->getModel();

        $fields = $model instanceof InteractsWithIndex ? $model->indexed() : [];

        return function (string $key) use ($exclude, $fields, $map): ?string {
            if ($exclude !== null && $key === $exclude) {
                return null;
            }

            if ($key === 'id') {
                return 'id';
            }

            if (isset($fields[$key])) {
                return $fields[$key];
            }

            if (isset($map[$key])) {
                return $map[$key];
            }

            return $this->formatFacetField($key);
        };
    }

    /** Log unresolved filter keys. */
    private function logUnresolved(array $unresolved, string $entityType): void
    {
        foreach (array_keys($unresolved) as $key) {
            $this->logger->warning("eav.search: filter key [{$key}] has no indexed field, condition dropped", [
                'entity_type' => $entityType,
            ]);
        }
    }

    /** Hydrate and enrich facet distributions. */
    private function hydrateFacets(array $results, Builder $builder): array
    {
        $facets = [];
        $mainResult = $results['main'];

        $baseDistributions = $mainResult->getFacetDistribution() ?? [];
        $baseStats = $mainResult->getFacetStats() ?? [];

        $attributes = $this->loadContextAttributes($builder);
        $locale = $this->localeRegistry->current();

        foreach ($builder->getFacets() as $key) {
            $indexField = $this->formatFacetField($key);

            $scopedResult = $builder->hasFilter($key) ? ($results["facet_{$key}"] ?? $mainResult) : $mainResult;
            $isMainResult = $scopedResult === $mainResult;

            if (str_contains($key, '.')) {
                $stats = $isMainResult ? $baseStats : ($scopedResult->getFacetStats() ?? []);
                $facet = $this->hydrateStats($stats, $indexField);
            } else {
                $distributions = $isMainResult ? $baseDistributions : ($scopedResult->getFacetDistribution() ?? []);
                $facet = $this->hydrateDistribution($distributions, $indexField, $attributes->get($key), $locale);
            }

            if (! empty($facet)) {
                $facets[$indexField] = $facet;
            }
        }

        return $facets;
    }

    /** Extract and format numeric stats facet. */
    private function hydrateStats(array $stats, string $field): array
    {
        $fieldStats = $stats[$field] ?? null;

        if ($fieldStats === null) {
            return [];
        }

        return [
            'min' => $fieldStats['min'] ?? null,
            'max' => $fieldStats['max'] ?? null,
        ];
    }

    /** Extract and enrich term distribution facet. */
    private function hydrateDistribution(array $distributions, string $field, mixed $attribute, int $locale): array
    {
        $distribution = $distributions[$field] ?? [];

        if (empty($distribution) || $attribute === null) {
            return $distribution;
        }

        return $this->fieldFactory->make($attribute)->enrichFacetDistribution($distribution, $locale);
    }

    /** Load filterable attributes context. */
    private function loadContextAttributes(Builder $builder): Collection
    {
        return $this->schemaRegistry->resolve("{$builder->getEntityType()}:search_facets", function () use ($builder): Collection {
            return Eav::$attributeModel::query()
                ->forEntity($builder->getEntityType())
                ->where('filterable', true)
                ->with('type')
                ->get()
                ->keyBy('code');
        });
    }

    /** Format facet field name for index. */
    private function formatFacetField(string $key): string
    {
        return !str_contains($key, '.') ? "attributes.{$key}" : $key;
    }
}