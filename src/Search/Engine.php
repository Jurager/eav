<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Support\Arr;
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

    public function search(Builder $builder, int $perPage = 15, int $page = 1): Result
    {
        $model = $builder->getModel();

        if (! $model || ! method_exists($model, 'searchableAs')) {
            return new Result([], 0, []);
        }

        $indexUid = $model->searchableAs();
        $resolver = $this->createResolver($builder);

        $this->logUnresolved($this->compiler->unresolved($builder->getFilter(), $resolver), $builder->getEntityType());

        $requests = $this->buildSearchRequests($builder, $indexUid, $resolver, $perPage, $page);
        $keys = array_keys($requests);

        try {
            $response = $this->meilisearch->multiSearch(array_values($requests));
        } catch (ApiException $e) {
            throw new BadRequestHttpException("Invalid search request: {$e->message}", $e);
        }

        $searchResults = [];
        foreach ($response['results'] as $index => $res) {
            $searchResults[$keys[$index]] = new MeilisearchResult($res);
        }

        $main = $searchResults['main'];

        return new Result(
            ids: array_column($main->getHits(), 'id'),
            total: $main->getEstimatedTotalHits() ?? 0,
            facets: Arr::undot($this->hydrateFacets($searchResults, $builder))
        );
    }

    private function buildSearchRequests(Builder $builder, string $indexUid, \Closure $resolver, int $perPage, int $page): array
    {
        $facetFields = [];
        foreach ($builder->getFacets() as $facet) {
            $facetFields[] = $this->formatFacetField($facet);
        }

        $requests = [
            'main' => array_filter([
                'indexUid' => $indexUid,
                'q'        => $builder->getQuery(),
                'filter'   => $this->compiler->compile($builder->getFilter(), $resolver),
                'facets'   => $facetFields ?: null,
                'limit'    => $perPage,
                'offset'   => ($page - 1) * $perPage,
            ])
        ];

        foreach ($builder->getFacets() as $key) {
            if ($builder->hasFilter($key)) {
                $field = $this->formatFacetField($key);
                $excludeResolver = $this->createResolver($builder, $key);

                $requests["facet_{$key}"] = [
                    'indexUid' => $indexUid,
                    'q'        => $builder->getQuery(),
                    'filter'   => $this->compiler->compile($builder->getFilter(), $excludeResolver),
                    'facets'   => [$field],
                    'limit'    => 0,
                ];
            }
        }

        return $requests;
    }

    private function createResolver(Builder $builder, ?string $exclude = null): \Closure
    {
        $model = $builder->getModel();
        $map = $builder->getMap();

        return function (string $key) use ($exclude, $model, $map): ?string {
            if ($exclude !== null && $key === $exclude) {
                return null;
            }

            if ($key === 'id') {
                return 'id';
            }

            if ($model instanceof InteractsWithIndex && ($field = $model->indexed()[$key] ?? null) !== null) {
                return $field;
            }

            if (isset($map[$key])) {
                return $map[$key];
            }

            return $this->formatFacetField($key);
        };
    }

    private function logUnresolved(array $unresolved, string $entityType): void
    {
        foreach (array_keys($unresolved) as $key) {
            $this->logger->warning("eav.search: filter key [{$key}] has no indexed field, condition dropped", [
                'entity_type' => $entityType,
            ]);
        }
    }

    private function hydrateFacets(array $searchResults, Builder $builder): array
    {
        $facets = [];
        $main = $searchResults['main'];

        $mainDistribution = $main->getFacetDistribution() ?? [];
        $mainStats = $main->getFacetStats() ?? [];

        $contextAttributes = $this->loadContextAttributes($builder);
        $localeId = $this->localeRegistry->current();

        foreach ($builder->getFacets() as $key) {
            $field = $this->formatFacetField($key);
            $response = $builder->hasFilter($key) ? ($searchResults["facet_{$key}"] ?? $main) : $main;

            if (!str_contains($key, '.')) {
                $raw = ($response->getFacetDistribution() ?? $mainDistribution)[$field] ?? [];
                
                if (!empty($raw) && $attribute = $contextAttributes->get($key)) {
                    $facet = $this->fieldFactory->make($attribute)->enrichFacetDistribution($raw, $localeId);
                } else {
                    $facet = $raw;
                }
            } else {
                $stats = $response->getFacetStats() ?? $mainStats;
                $facet = isset($stats[$field]) ? ['min' => $stats[$field]['min'] ?? null, 'max' => $stats[$field]['max'] ?? null] : [];
            }

            if (! empty($facet)) {
                $facets[$field] = $facet;
            }
        }

        return $facets;
    }

    private function loadContextAttributes(Builder $builder)
    {
        return $this->schemaRegistry->resolve("{$builder->getEntityType()}:search_facets", function () use ($builder) {
            return Eav::$attributeModel::query()
                ->forEntity($builder->getEntityType())
                ->where('filterable', true)
                ->with('type')
                ->get()
                ->keyBy('code');
        });
    }

    private function formatFacetField(string $key): string
    {
        return !str_contains($key, '.') ? "attributes.{$key}" : $key;
    }
}
