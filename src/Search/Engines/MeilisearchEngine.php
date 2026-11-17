<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines;

use Jurager\Eav\Contracts\Search\BoostsMatches;
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Contracts\Search\FederatesSearches;
use Jurager\Eav\Contracts\Search\SearchesFacetValues;
use Jurager\Eav\Contracts\Search\UpdatesIndexSettings;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Engines\Meilisearch\Gateway;
use Jurager\Eav\Search\Engines\Meilisearch\RequestFactory;
use Jurager\Eav\Search\Engines\Meilisearch\Settings;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Eav\Search\Response;

/**
 * Search with Meilisearch: multi-search requests, native federation and boosting, facet search and index settings.
 */
class MeilisearchEngine implements BoostsMatches, Engine, FederatesSearches, SearchesFacetValues, UpdatesIndexSettings
{
    public function __construct(
        private readonly Gateway $meilisearch,
        private readonly RequestFactory $requests,
        private readonly Settings $settings,
    ) {}

    public function search(Builder $search, int $perPage, int $page): Response
    {
        // Nothing to order when no hits are asked for, as when counting.
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

        $hits = match (true) {
            $partitioned => $this->partition($search, $responses['leading'], $perPage, $page),
            $boosted => $this->meilisearch->multiSearch($this->queries($search), ['limit' => $perPage, 'offset' => ($page - 1) * $perPage])['hits'] ?? [],
            default => $main['hits'],
        };

        return new Response(
            $this->hits($search, $hits),
            (int) ($main['totalHits'] ?? $main['estimatedTotalHits'] ?? 0),
            $this->facets($search, $facets, $main, $responses),
        );
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
                $federation['facetsByIndex'][$search->index->uid()] = array_values($facets);
            }
        }

        $response = $this->meilisearch->multiSearch($queries, $federation);
        $hits = [];

        foreach ($response['hits'] ?? [] as $hit) {
            if (($search = $owners[$hit['_federation']['queriesPosition'] ?? -1] ?? null) !== null) {
                array_push($hits, ...$this->hits($search, [$hit]));
            }
        }

        return new Response($hits, (int) ($response['estimatedTotalHits'] ?? count($hits)), $this->federatedFacets($searches, $response));
    }

    public function facetValues(Builder $search, IndexField $field, ?string $query): array
    {
        $request = $this->requests->make($search, except: [$field->path]);

        $response = $this->meilisearch->facetSearch($search->index->uid(), array_filter([
            'facetName' => $search->path($field),
            'facetQuery' => $query,
            'q' => $request['q'] ?? null,
            'filter' => $request['filter'] ?? null,
            'attributesToSearchOn' => $request['attributesToSearchOn'] ?? null,
            'matchingStrategy' => $request['matchingStrategy'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        return array_column($response['facetHits'] ?? [], 'count', 'value');
    }

    public function updateIndexSettings(Index $index): void
    {
        $this->meilisearch->updateSettings($index->uid(), $this->settings->for($index));
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
     * @param  list<array<string, mixed>>  $hits
     * @return list<Hit>
     */
    private function hits(Builder $search, array $hits): array
    {
        return array_map(static fn (array $hit): Hit => $search->hit(
            $hit['id'],
            array_filter($hit, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY),
            $hit['_formatted'] ?? [],
            isset($hit['_rankingScore']) ? (float) $hit['_rankingScore'] : null,
        ), array_values($hits));
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
    private function facets(Builder $search, array $facets, array $response, array $scoped = []): array
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
            $data = $response['facetsByIndex'][$search->index->uid()] ?? null;

            if ($data === null) {
                continue;
            }

            $own = [];

            foreach ($scoped as $key => $result) {
                if (str_starts_with($key, "{$position}:")) {
                    $own[substr($key, strlen("{$position}:"))] = $result;
                }
            }

            $found[$search->index->entityType] = $this->facets($search, $this->facetPaths($search), [
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
