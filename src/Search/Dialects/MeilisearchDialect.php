<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Dialects;

use Closure;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Dialects\Meilisearch\Gateway;
use Jurager\Eav\Search\Dialects\Meilisearch\RequestFactory;
use Jurager\Eav\Search\Dialects\Meilisearch\Settings;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Eav\Search\Response;

/**
 * What Scout's Meilisearch engine is asked beyond Scout's own query API: groups of conditions, facets,
 * per-locale search, boosting, partitioning, native federation, facet search and derived index settings.
 */
class MeilisearchDialect extends Dialect
{
    public function __construct(
        private readonly Gateway $meilisearch,
        private readonly RequestFactory $requests,
        private readonly Settings $settings,
    ) {}

    public function extended(): bool
    {
        return true;
    }

    /**
     * Conditions are kept on the search and written as one filter expression when it runs.
     */
    public function where(Builder $search, Group $group): void {}

    /**
     * Sorts are kept on the search too: the path of a localized field depends on the locale searched.
     */
    public function orderBy(Builder $search, IndexField $field, string $direction): void {}

    public function callback(Builder $search): Closure
    {
        // Scout hands over its own parameters: paging, and whatever was set through options().
        return function (mixed $index, ?string $query, array $parameters) use ($search): array {
            // Anything else Scout adds, such as semantic search parameters, travels as options of this run.
            $extra = array_diff_key($parameters, $search->options, array_flip(['filter', 'sort', 'limit', 'hitsPerPage', 'page']));

            if ($extra !== []) {
                $search = clone $search;
                $search->options = [...$search->options, ...$extra];
            }

            // Scout leaves a limit of zero out: asking for no hits, as when counting, is read off the search.
            return $this->search($search, (int) ($parameters['hitsPerPage'] ?? $search->limit ?? 20), (int) ($parameters['page'] ?? 1));
        };
    }

    public function hits(Builder $search, mixed $results): array
    {
        return array_map(static fn (array $hit): Hit => $search->hit(
            $hit['id'],
            array_filter($hit, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY),
            $hit['_formatted'] ?? [],
            isset($hit['_rankingScore']) ? (float) $hit['_rankingScore'] : null,
        ), array_values($results['hits'] ?? []));
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
    private function search(Builder $search, int $perPage, int $page): array
    {
        // Nothing to order when no hits are asked for.
        $partitioned = $search->partition !== null && $perPage > 0;
        $boosted = $search->boosts() !== [] && $perPage > 0;
        $facets = $this->facetPaths($search);

        // Partitioned and boosted hits are fetched on their own; the main request then only counts and facets.
        $requests = ['main' => [
            ...$this->requests->make($search),
            'hitsPerPage' => $partitioned || $boosted ? 0 : $perPage,
            'page' => $partitioned || $boosted ? 1 : $page,
            ...($facets === [] ? [] : ['facets' => array_values($facets)]),
        ]];

        if ($partitioned) {
            $requests['leading'] = [...$this->requests->make($search, side: $search->partitionFirst), 'hitsPerPage' => $perPage, 'page' => $page];
        }

        $requests += $this->scopedFacetRequests($search, $facets);

        $responses = array_combine(array_keys($requests), $this->meilisearch->multiSearch(array_values($requests))['results']);
        $main = $responses['main'];

        return [
            'hits' => match (true) {
                $partitioned => $this->partition($search, $responses['leading'], $perPage, $page),
                $boosted => $this->meilisearch->multiSearch($this->queries($search), ['limit' => $perPage, 'offset' => ($page - 1) * $perPage])['hits'] ?? [],
                default => $main['hits'],
            },
            'totalHits' => (int) ($main['totalHits'] ?? $main['estimatedTotalHits'] ?? 0),
            'facets' => $this->facetData($search, $facets, $main, $responses),
        ];
    }

    public function federated(array $searches, int $limit, int $offset): Response
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

        $response = $this->meilisearch->multiSearch($queries, $federation);
        $hits = [];

        foreach ($response['hits'] ?? [] as $hit) {
            if (($search = $owners[$hit['_federation']['queriesPosition'] ?? -1] ?? null) !== null) {
                array_push($hits, ...$this->hits($search, ['hits' => [$hit]]));
            }
        }

        return new Response($hits, (int) ($response['estimatedTotalHits'] ?? count($hits)), $this->federatedFacets($searches, $response));
    }

    public function facetValues(Builder $search, string $name, IndexField $field, ?string $query): array
    {
        $request = $this->requests->make($search, except: [$field->path]);

        $response = $this->meilisearch->facetSearch($search->uid(), array_filter([
            'facetName' => $search->path($field),
            'facetQuery' => $query,
            'q' => $request['q'] ?? null,
            'filter' => $request['filter'] ?? null,
            'attributesToSearchOn' => $request['attributesToSearchOn'] ?? null,
            'matchingStrategy' => $request['matchingStrategy'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        return array_column($response['facetHits'] ?? [], 'count', 'value');
    }

    public function settings(Index $index): array
    {
        return $this->settings->for($index);
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

        $trailing = $this->meilisearch->multiSearch([[
            ...$this->requests->make($search, side: ! $search->partitionFirst),
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
            ...$this->requests->make($search),
            ...($search->weight === null ? [] : ['federationOptions' => ['weight' => $weight]]),
        ]];

        foreach ($search->boosts() as [$condition, $boost]) {
            $queries[] = [...$this->requests->make($search, boosted: $condition), 'federationOptions' => ['weight' => $weight * $boost]];
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
                $requests["facet:{$name}"] = [...$this->requests->make($search, except: [$field->path]), 'limit' => 0, 'facets' => [$path]];
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
            : array_combine(array_keys($requests), $this->meilisearch->multiSearch(array_values($requests))['results']);

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
}
