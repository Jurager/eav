<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines;

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
 * Search with Meilisearch: multi-search requests, native federation, facet search and index settings.
 */
class MeilisearchEngine implements Engine, FederatesSearches, SearchesFacetValues, UpdatesIndexSettings
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
        $facets = $this->facetPaths($search);

        // With a partition the main request only counts and facets; the hits come from each side in turn.
        $requests = ['main' => [
            ...$this->requests->make($search),
            'hitsPerPage' => $partitioned ? 0 : $perPage,
            'page' => $partitioned ? 1 : $page,
            ...($facets === [] ? [] : ['facets' => array_values($facets)]),
        ]];

        if ($partitioned) {
            $requests['leading'] = [...$this->requests->make($search, side: $search->partitionFirst), 'hitsPerPage' => $perPage, 'page' => $page];
        }

        // A facet counts as if its own filter were not applied, so its other values stay selectable.
        foreach ($facets as $name => $path) {
            $field = $search->filterable($name);

            if ($search->constrains($field)) {
                $requests["facet:{$name}"] = [...$this->requests->make($search, except: [$field->path]), 'limit' => 0, 'facets' => [$path]];
            }
        }

        $responses = array_combine(array_keys($requests), $this->meilisearch->multiSearch(array_values($requests))['results']);
        $main = $responses['main'];
        $found = [];

        foreach ($facets as $name => $path) {
            $found[$name] = $this->facet($search->filterable($name), $path, $responses["facet:{$name}"] ?? $main);
        }

        return new Response(
            $this->hits($search, $partitioned ? $this->partition($search, $responses['leading'], $perPage, $page) : $main['hits']),
            (int) ($main['totalHits'] ?? $main['estimatedTotalHits'] ?? 0),
            $found,
        );
    }

    public function federated(array $searches, int $limit, int $offset): Response
    {
        $queries = [];
        $federation = ['limit' => $limit, 'offset' => $offset];

        foreach ($searches as $search) {
            $queries[] = [
                ...$this->requests->make($search),
                ...($search->weight === null ? [] : ['federationOptions' => ['weight' => $search->weight]]),
            ];

            if (($facets = $this->facetPaths($search)) !== []) {
                $federation['facetsByIndex'][$search->index->uid()] = array_values($facets);
            }
        }

        $response = $this->meilisearch->multiSearch($queries, $federation);
        $hits = [];
        $found = [];

        foreach ($response['hits'] ?? [] as $hit) {
            if (($search = $searches[$hit['_federation']['queriesPosition'] ?? -1] ?? null) !== null) {
                array_push($hits, ...$this->hits($search, [$hit]));
            }
        }

        foreach ($searches as $search) {
            $data = $response['facetsByIndex'][$search->index->uid()] ?? null;

            if ($data === null) {
                continue;
            }

            foreach ($this->facetPaths($search) as $name => $path) {
                $found[$search->index->entityType][$name] = $this->facet($search->filterable($name), $path, [
                    'facetDistribution' => $data['distribution'] ?? [],
                    'facetStats' => $data['stats'] ?? [],
                ]);
            }
        }

        return new Response($hits, (int) ($response['estimatedTotalHits'] ?? count($hits)), $found);
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
