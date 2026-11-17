<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Jurager\Eav\Search\Engines\MeilisearchEngine;
use Jurager\Eav\Search\Grammars\MeilisearchGrammar;
use Meilisearch\Client;
use PHPUnit\Framework\Assert;

/**
 * The package's Meilisearch engine with no server behind it: it records the requests it would send and
 * answers with prepared hits. Indexing does nothing, and settings are recorded.
 */
class RecordingMeilisearchEngine extends MeilisearchEngine
{
    public function __construct(bool $softDelete = false)
    {
        parent::__construct(new Client('http://127.0.0.1:1'), $softDelete, app(MeilisearchGrammar::class));
    }

    public function update($models) {}

    public function delete($models) {}

    public function flush($model) {}

    public function updateIndexSettings($name, array $settings = [])
    {
        $this->settings[$name] = $settings;
    }


    /** @var list<array{queries: list<array<string, mixed>>, federation: array<string, mixed>|null}> */
    public array $searches = [];

    /** @var list<array{index: string, query: array<string, mixed>}> */
    public array $facetSearches = [];

    /** @var array<string, array<string, mixed>> Settings sent, keyed by index. */
    public array $settings = [];

    /** @var array<string, array<string, mixed>> Prepared results keyed by index. */
    private array $results = [];

    /**
     * Answer searches of an index with the given hits.
     *
     * @param  list<array<string, mixed>>  $hits  Documents, each with at least an `id`.
     * @param  array<string, array<string, int>>  $facets  Facet distribution keyed by index path.
     * @param  array<string, array{min: int|float, max: int|float}>  $stats  Facet stats keyed by index path.
     */
    public function respond(string $index, array $hits, ?int $total = null, array $facets = [], array $stats = []): static
    {
        $this->results[$index] = [
            'hits' => array_values($hits),
            'total' => $total ?? count($hits),
            'facetDistribution' => $facets,
            'facetStats' => $stats,
        ];

        return $this;
    }

    protected function multiSearch(array $queries, ?array $federation = null): array
    {
        $this->searches[] = ['queries' => $queries, 'federation' => $federation];

        if ($federation !== null) {
            return $this->federatedResponse($queries, $federation);
        }

        return ['results' => array_map(function (array $query): array {
            $result = $this->results[$query['indexUid']] ?? ['hits' => [], 'total' => 0, 'facetDistribution' => [], 'facetStats' => []];
            $perPage = $query['hitsPerPage'] ?? $query['limit'] ?? 20;
            $offset = isset($query['page']) ? ($query['page'] - 1) * $perPage : ($query['offset'] ?? 0);

            return [
                'indexUid' => $query['indexUid'],
                'hits' => array_slice($result['hits'], $offset, $perPage),
                'totalHits' => $result['total'],
                'estimatedTotalHits' => $result['total'],
                'facetDistribution' => $result['facetDistribution'],
                'facetStats' => $result['facetStats'],
            ];
        }, $queries)];
    }

    protected function facetSearch(string $index, array $query): array
    {
        $this->facetSearches[] = ['index' => $index, 'query' => $query];

        $hits = [];

        foreach ($this->results[$index]['facetDistribution'][$query['facetName']] ?? [] as $value => $count) {
            $hits[] = ['value' => (string) $value, 'count' => $count];
        }

        return ['facetHits' => $hits, 'facetQuery' => $query['facetQuery'] ?? null];
    }


    /**
     * The search requests sent so far, flattened across round trips.
     *
     * @return list<array<string, mixed>>
     */
    public function queries(): array
    {
        return array_merge(...array_column($this->searches, 'queries'), ...[[]]);
    }

    /**
     * Assert a search request passing the test was sent.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $test
     */
    public function assertSearched(?callable $test = null): void
    {
        $matching = array_filter($this->queries(), $test ?? static fn (): bool => true);

        Assert::assertNotEmpty($matching, 'The expected search request was not sent.');
    }

    public function assertNothingSearched(): void
    {
        Assert::assertSame([], $this->searches, 'Search requests were sent unexpectedly.');
    }

    /**
     * @param  list<array<string, mixed>>  $queries
     * @param  array<string, mixed>  $federation
     * @return array<string, mixed>
     */
    private function federatedResponse(array $queries, array $federation): array
    {
        $hits = [];
        $facets = [];

        foreach ($queries as $position => $query) {
            $result = $this->results[$query['indexUid']] ?? null;

            foreach ($result['hits'] ?? [] as $hit) {
                $hits[] = $hit + ['_federation' => ['indexUid' => $query['indexUid'], 'queriesPosition' => $position]];
            }

            if (isset($federation['facetsByIndex'][$query['indexUid']])) {
                $facets[$query['indexUid']] = [
                    'distribution' => $result['facetDistribution'] ?? [],
                    'stats' => $result['facetStats'] ?? [],
                ];
            }
        }

        return [
            'hits' => array_slice($hits, $federation['offset'] ?? 0, $federation['limit'] ?? 20),
            'estimatedTotalHits' => count($hits),
            'facetsByIndex' => $facets,
        ];
    }
}
