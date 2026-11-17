<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Eav\Contracts\Search\Transport;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Run search builders against the engine.
 */
class Engine
{
    public function __construct(
        private readonly Transport $transport,
        private readonly RequestFactory $requests,
        private readonly FacetFormatter $facets,
    ) {}

    /**
     * Run a search.
     *
     * @return array{hits: list<Hit>, total: int, facets: array<string, mixed>}
     *
     * @throws InvalidSearchQueryException
     */
    public function search(Builder $builder, int $perPage, int $page): array
    {
        // Nothing to order when no hits are asked for, as when counting.
        $partitioned = $builder->partition !== null && $perPage > 0;
        $facets = $this->facetFields($builder);

        // With a partition the main request only counts and facets; the hits come from each side in turn.
        $requests = ['main' => [
            ...$this->requests->make($builder),
            'hitsPerPage' => $partitioned ? 0 : $perPage,
            'page' => $partitioned ? 1 : $page,
            ...$this->facetPaths($builder, $facets),
        ]];

        if ($partitioned) {
            $requests['leading'] = [...$this->requests->make($builder, side: $builder->partitionFirst), 'hitsPerPage' => $perPage, 'page' => $page];
        }

        // A facet counts as if its own filter were not applied, so its other values stay selectable.
        foreach ($facets as $name => $field) {
            if ($builder->constrains($field)) {
                $requests["facet:{$name}"] = [
                    ...$this->requests->make($builder, except: [$field->path]),
                    'limit' => 0,
                    'facets' => [$builder->path($field)],
                ];
            }
        }

        $responses = array_combine(array_keys($requests), $this->transport->multiSearch(array_values($requests))['results']);
        $main = $responses['main'];
        $scoped = [];

        foreach (array_keys($facets) as $name) {
            if (isset($responses["facet:{$name}"])) {
                $scoped[$name] = $responses["facet:{$name}"];
            }
        }

        return [
            'hits' => $this->hits($builder, $partitioned ? $this->partition($builder, $responses['leading'], $perPage, $page) : $main['hits']),
            'total' => (int) ($main['totalHits'] ?? $main['estimatedTotalHits'] ?? 0),
            'facets' => $this->facets->format($builder, $facets, $main, $scoped),
        ];
    }

    /**
     * Run several searches as one, their hits merged into a single ranked list.
     *
     * @param  list<Builder>  $builders
     * @return array{hits: list<Hit>, total: int, facets: array<string, array<string, mixed>>}
     *
     * @throws InvalidSearchQueryException
     */
    public function federated(array $builders, int $limit, int $offset): array
    {
        $queries = [];
        $federation = ['limit' => $limit, 'offset' => $offset];

        foreach ($builders as $builder) {
            $queries[] = [
                ...$this->requests->make($builder),
                ...($builder->weight === null ? [] : ['federationOptions' => ['weight' => $builder->weight]]),
            ];

            if (($paths = $this->facetPaths($builder, $this->facetFields($builder))) !== []) {
                $federation['facetsByIndex'][$builder->index->uid()] = $paths['facets'];
            }
        }

        $response = $this->transport->multiSearch($queries, $federation);
        $hits = [];
        $facets = [];

        foreach ($response['hits'] ?? [] as $hit) {
            if (($builder = $builders[$hit['_federation']['queriesPosition'] ?? -1] ?? null) !== null) {
                array_push($hits, ...$this->hits($builder, [$hit]));
            }
        }

        foreach ($builders as $builder) {
            $found = $response['facetsByIndex'][$builder->index->uid()] ?? null;

            if ($found !== null && $builder->facets !== []) {
                $facets[$builder->index->entityType] = $this->facets->format($builder, $this->facetFields($builder), [
                    'facetDistribution' => $found['distribution'] ?? [],
                    'facetStats' => $found['stats'] ?? [],
                ]);
            }
        }

        return ['hits' => $hits, 'total' => (int) ($response['estimatedTotalHits'] ?? count($hits)), 'facets' => $facets];
    }

    /**
     * Search the values of one facet, within the builder's query and its other filters.
     *
     * @return list<array{value: string, label: string, count: int}>
     *
     * @throws InvalidSearchQueryException
     */
    public function facetValues(Builder $builder, string $name, ?string $query): array
    {
        $field = $builder->filterable($name);
        $request = $this->requests->make($builder, except: [$field->path]);

        $response = $this->transport->facetSearch($builder->index->uid(), array_filter([
            'facetName' => $builder->path($field),
            'facetQuery' => $query,
            'q' => $request['q'] ?? null,
            'filter' => $request['filter'] ?? null,
            'attributesToSearchOn' => $request['attributesToSearchOn'] ?? null,
            'matchingStrategy' => $request['matchingStrategy'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        return $this->facets->terms($builder, $field, array_column($response['facetHits'] ?? [], 'count', 'value'));
    }

    /**
     * Fill a partitioned page: the leading side first, topped up from the trailing side once it runs out.
     *
     * @param  array<string, mixed>  $leading
     * @return list<array<string, mixed>>
     */
    private function partition(Builder $builder, array $leading, int $perPage, int $page): array
    {
        $hits = $leading['hits'];
        $missing = $perPage - count($hits);

        if ($missing <= 0) {
            return $hits;
        }

        $trailing = $this->transport->multiSearch([[
            ...$this->requests->make($builder, side: ! $builder->partitionFirst),
            'limit' => $missing,
            'offset' => max(0, ($page - 1) * $perPage - (int) ($leading['totalHits'] ?? 0)),
        ]])['results'][0];

        return [...$hits, ...$trailing['hits']];
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<Hit>
     */
    private function hits(Builder $builder, array $hits): array
    {
        return array_map(static function (array $hit) use ($builder): Hit {
            $score = $hit['_rankingScore'] ?? null;

            return new Hit(
                key: $hit['id'],
                entityType: $builder->index->entityType,
                document: array_filter($hit, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY),
                formatted: $hit['_formatted'] ?? [],
                score: $score === null ? null : (float) $score,
                search: $builder,
            );
        }, array_values($hits));
    }

    /**
     * Fields the facets were asked for, keyed by the asked name.
     *
     * @return array<string, IndexField>
     */
    private function facetFields(Builder $builder): array
    {
        $fields = [];

        foreach ($builder->facets as $name) {
            $fields[$name] = $builder->filterable($name);
        }

        return $fields;
    }

    /**
     * The facets parameter of a request, or nothing when no facets are asked for.
     *
     * @param  array<string, IndexField>  $fields
     * @return array{facets?: list<string>}
     */
    private function facetPaths(Builder $builder, array $fields): array
    {
        return $fields === [] ? [] : ['facets' => array_values(array_map($builder->path(...), $fields))];
    }
}
