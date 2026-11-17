<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines;

use Jurager\Eav\Contracts\Search\DerivesIndexSettings;
use Jurager\Eav\Contracts\Search\FederatesSearches;
use Jurager\Eav\Contracts\Search\SupportsExtendedSearch;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Grammars\MeilisearchGrammar;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Engines\MeilisearchEngine as ScoutMeilisearchEngine;
use Meilisearch\Client;
use Meilisearch\Contracts\FacetSearchQuery;
use Meilisearch\Contracts\MultiSearchFederation;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Scout's Meilisearch engine, taught what Scout's query API cannot ask: groups of conditions, facets,
 * per-locale search, boosting, partitioning, native federation, facet search and derived index settings.
 *
 * Indexing, and searches of models without attributes, are left to Scout's engine as they are.
 */
class MeilisearchEngine extends ScoutMeilisearchEngine implements DerivesIndexSettings, FederatesSearches, SupportsExtendedSearch
{
    public function __construct(
        Client $meilisearch,
        bool $softDelete,
        private readonly MeilisearchGrammar $grammar,
    ) {
        parent::__construct($meilisearch, $softDelete);
    }

    public function search(ScoutBuilder $builder)
    {
        // A callback given to search() is the caller taking the request into their own hands.
        return $builder instanceof Builder && $builder->callback === null
            ? $this->run($builder, (int) ($builder->limit ?? 20), 1)
            : parent::search($builder);
    }

    public function paginate(ScoutBuilder $builder, $perPage, $page)
    {
        return $builder instanceof Builder && $builder->callback === null
            ? $this->run($builder, (int) $perPage, (int) $page)
            : parent::paginate($builder, $perPage, $page);
    }

    public function facets(Builder $search, mixed $results): array
    {
        return $results['facets'] ?? [];
    }

    /**
     * Run a search and answer as Meilisearch would, with the facets already keyed by the asked names.
     *
     * @return array{hits: list<array<string, mixed>>, totalHits: int, facets: array<string, mixed>}
     */
    private function run(Builder $search, int $perPage, int $page): array
    {
        // Nothing to order when no hits are asked for.
        $partitioned = $search->partition !== null && $perPage > 0;
        $boosted = $search->boosts() !== [] && $perPage > 0;
        $facets = $this->facetPaths($search);

        // Partitioned and boosted hits are fetched on their own; the main request then only counts and facets.
        $requests = ['main' => [
            ...$this->request($search),
            'hitsPerPage' => $partitioned || $boosted ? 0 : $perPage,
            'page' => $partitioned || $boosted ? 1 : $page,
            ...($facets === [] ? [] : ['facets' => array_values($facets)]),
        ]];

        if ($partitioned) {
            $requests['leading'] = [...$this->request($search, side: $search->partitionFirst), 'hitsPerPage' => $perPage, 'page' => $page];
        }

        $requests += $this->scopedFacetRequests($search, $facets);

        $responses = array_combine(array_keys($requests), $this->multiSearch(array_values($requests))['results']);
        $main = $responses['main'];

        return [
            'hits' => match (true) {
                $partitioned => $this->partition($search, $responses['leading'], $perPage, $page),
                $boosted => $this->multiSearch($this->queries($search), ['limit' => $perPage, 'offset' => ($page - 1) * $perPage])['hits'] ?? [],
                default => $main['hits'],
            },
            'totalHits' => (int) ($main['totalHits'] ?? $main['estimatedTotalHits'] ?? 0),
            'facets' => $this->facetData($search, $facets, $main, $responses),
        ];
    }

    public function federated(array $searches, int $limit, int $offset): array
    {
        $queries = [];
        $owners = [];
        $federation = ['limit' => $limit, 'offset' => $offset];

        foreach ($searches as $search) {
            // A boosted search is several queries over its index; the server keeps each document once.
            foreach ($this->queries($search) as $query) {
                $queries[] = $query;
                $owners[] = $search;
            }

            if (($facets = $this->facetPaths($search)) !== []) {
                $federation['facetsByIndex'][$search->uid()] = array_values($facets);
            }
        }

        $response = $this->multiSearch($queries, $federation);
        $hits = [];

        foreach ($response['hits'] ?? [] as $hit) {
            if (($search = $owners[$hit['_federation']['queriesPosition'] ?? -1] ?? null) !== null) {
                $hits[] = [$search, $hit];
            }
        }

        return [
            'hits' => $hits,
            'total' => (int) ($response['estimatedTotalHits'] ?? count($hits)),
            'facets' => $this->federatedFacets($searches, $response),
        ];
    }

    public function facetValues(Builder $search, string $name, IndexField $field, ?string $query): array
    {
        $request = $this->request($search, except: [$field->path]);

        $response = $this->facetSearch($search->uid(), array_filter([
            'facetName' => $search->path($field),
            'facetQuery' => $query,
            'q' => $request['q'] ?? null,
            'filter' => $request['filter'] ?? null,
            'attributesToSearchOn' => $request['attributesToSearchOn'] ?? null,
            'matchingStrategy' => $request['matchingStrategy'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        return array_column($response['facetHits'] ?? [], 'count', 'value');
    }

    public function indexSettings(Index $index): array
    {
        return $this->grammar->compileSettings($index);
    }

    /**
     * The request of a search: as the grammar writes it, with Scout's semantic search parameters.
     *
     * @param  list<string>  $except  Paths of fields whose top-level conditions are left out.
     * @param  bool|null  $side  Which side of the partition to take: its matches, or the rest; null for both.
     * @param  Group|null  $boosted  A boosted condition to narrow the search to.
     * @return array<string, mixed>
     */
    private function request(Builder $search, array $except = [], ?bool $side = null, ?Group $boosted = null): array
    {
        return [
            ...$this->grammar->compileSearch($search, $except, $side, $boosted),
            ...$this->semanticSearchParameters($search),
        ];
    }

    /**
     * Fill a partitioned page: the leading side first, topped up from the trailing side once it runs out.
     *
     * @param  array<string, mixed>  $leading
     * @return list<array<string, mixed>>
     */
    private function partition(Builder $search, array $leading, int $perPage, int $page): array
    {
        $hits = $leading['hits'];
        $missing = $perPage - count($hits);

        if ($missing <= 0) {
            return $hits;
        }

        $trailing = $this->multiSearch([[
            ...$this->request($search, side: ! $search->partitionFirst),
            'limit' => $missing,
            'offset' => max(0, ($page - 1) * $perPage - (int) ($leading['totalHits'] ?? 0)),
        ]])['results'][0];

        return [...$hits, ...$trailing['hits']];
    }

    /**
     * Document paths of the facets asked for, keyed by the asked name.
     *
     * @return array<string, string>
     */
    private function facetPaths(Builder $search): array
    {
        $paths = [];

        foreach ($search->facets as $name) {
            $paths[$name] = $search->path($search->filterable($name));
        }

        return $paths;
    }

    /**
     * The queries of a search within a federation: the search itself, then one per boosted condition.
     *
     * @return list<array<string, mixed>>
     */
    private function queries(Builder $search): array
    {
        $weight = $search->weight ?? 1.0;
        $queries = [[
            ...$this->request($search),
            ...($search->weight === null ? [] : ['federationOptions' => ['weight' => $weight]]),
        ]];

        foreach ($search->boosts() as [$condition, $boost]) {
            $queries[] = [...$this->request($search, boosted: $condition), 'federationOptions' => ['weight' => $weight * $boost]];
        }

        return $queries;
    }

    /**
     * Requests counting each filtered facet as if its own filter were not applied, so its other values
     * stay selectable. Keyed `facet:{name}`.
     *
     * @param  array<string, string>  $facets
     * @return array<string, array<string, mixed>>
     */
    private function scopedFacetRequests(Builder $search, array $facets): array
    {
        $requests = [];

        foreach ($facets as $name => $path) {
            $field = $search->filterable($name);

            if ($search->constrains($field)) {
                $requests["facet:{$name}"] = [...$this->request($search, except: [$field->path]), 'limit' => 0, 'facets' => [$path]];
            }
        }

        return $requests;
    }

    /**
     * Facet data keyed by the asked names, each read from its own scoped response where there is one.
     *
     * @param  array<string, string>  $facets
     * @param  array<string, mixed>  $response
     * @param  array<string, array<string, mixed>>  $scoped
     * @return array<string, mixed>
     */
    private function facetData(Builder $search, array $facets, array $response, array $scoped = []): array
    {
        $found = [];

        foreach ($facets as $name => $path) {
            $found[$name] = $this->facet($search->filterable($name), $path, $scoped["facet:{$name}"] ?? $response);
        }

        return $found;
    }

    /**
     * Facets of a federated response, keyed by entity type: those of all its hits of that type.
     *
     * @param  list<Builder>  $searches
     * @param  array<string, mixed>  $response
     * @return array<string, array<string, mixed>>
     */
    private function federatedFacets(array $searches, array $response): array
    {
        $requests = [];

        // The federation cannot leave a facet's own filter out: ask for those facets apart, in one round trip.
        foreach ($searches as $position => $search) {
            foreach ($this->scopedFacetRequests($search, $this->facetPaths($search)) as $key => $request) {
                $requests["{$position}:{$key}"] = $request;
            }
        }

        $scoped = $requests === []
            ? []
            : array_combine(array_keys($requests), $this->multiSearch(array_values($requests))['results']);

        $found = [];

        foreach ($searches as $position => $search) {
            $data = $response['facetsByIndex'][$search->uid()] ?? null;

            if ($data === null) {
                continue;
            }

            $own = [];

            foreach ($scoped as $key => $result) {
                if (str_starts_with($key, "{$position}:")) {
                    $own[substr($key, strlen("{$position}:"))] = $result;
                }
            }

            $found[$search->definition->entityType] = $this->facetData($search, $this->facetPaths($search), [
                'facetDistribution' => $data['distribution'] ?? [],
                'facetStats' => $data['stats'] ?? [],
            ], $own);
        }

        return $found;
    }

    /**
     * One facet out of a response: `min` and `max` of a numeric field, counts by value of any other.
     *
     * @param  array<string, mixed>  $response
     * @return array<array-key, mixed>
     */
    private function facet(IndexField $field, string $path, array $response): array
    {
        if (! $field->type->isNumeric()) {
            return $response['facetDistribution'][$path] ?? [];
        }

        $stats = $response['facetStats'][$path] ?? null;

        return $stats === null ? [] : ['min' => $stats['min'] ?? null, 'max' => $stats['max'] ?? null];
    }

    /**
     * Run several searches in one round trip, merged into one result list when a federation is given.
     *
     * @param  list<array<string, mixed>>  $queries
     * @param  array<string, mixed>|null  $federation
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException When Meilisearch rejects a request.
     */
    protected function multiSearch(array $queries, ?array $federation = null): array
    {
        $queries = array_map(static fn (array $payload): SearchQuery => new class($payload) extends SearchQuery
        {
            public function __construct(private readonly array $payload) {}

            public function toArray(): array
            {
                return $this->payload;
            }
        }, $queries);

        $federation = $federation === null ? null : new class($federation) extends MultiSearchFederation
        {
            public function __construct(private readonly array $payload) {}

            public function toArray(): array
            {
                return $this->payload;
            }
        };

        return $this->send(fn (): array => $this->meilisearch->multiSearch($queries, $federation));
    }

    /**
     * Search the values of one facet.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException When Meilisearch rejects the request.
     */
    protected function facetSearch(string $index, array $query): array
    {
        $query = new class($query) extends FacetSearchQuery
        {
            public function __construct(private readonly array $payload) {}

            public function toArray(): array
            {
                return $this->payload;
            }
        };

        return $this->send(fn (): array => $this->meilisearch->index($index)->facetSearch($query)->toArray());
    }

    /**
     * Report a request the engine rejected as a client error, without echoing the raw request back.
     *
     * @param  callable(): array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        try {
            return $request();
        } catch (ApiException $e) {
            if ($e->errorType !== 'invalid_request') {
                throw $e;
            }

            throw InvalidSearchQueryException::rejected(explode("\n", (string) $e->getMessage(), 2)[0], $e);
        }
    }
}
