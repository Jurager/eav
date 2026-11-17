<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Testing;

use Jurager\Eav\Search\Builder;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Engines\NullEngine;
use PHPUnit\Framework\Assert;

/**
 * A Scout engine that answers searches with prepared hits and records them. Indexing does nothing.
 */
class FakeEngine extends NullEngine
{
    /** @var list<Builder> The searches run so far. */
    public array $searches = [];

    /** @var array<string, array{hits: list<array<string, mixed>>, total: int, facets: array<string, mixed>}> */
    private array $responses = [];

    /**
     * Answer searches over an entity type with the given hits.
     *
     * @param  string  $entityType  The entity type as the search index names it.
     * @param  list<int|string|array<string, mixed>>  $hits  Keys, or documents with an `id` and an optional `_score`.
     * @param  array<string, mixed>  $facets  A map of value to count, or `min` and `max`, per facet name.
     */
    public function respond(string $entityType, array $hits, ?int $total = null, array $facets = []): static
    {
        $this->responses[$entityType] = [
            'hits' => array_map(static fn (mixed $hit): array => is_array($hit) ? $hit : ['id' => $hit], array_values($hits)),
            'total' => $total ?? count($hits),
            'facets' => $facets,
        ];

        return $this;
    }

    public function search(ScoutBuilder $builder)
    {
        return $this->paginate($builder, $builder->limit ?? 20, 1);
    }

    public function paginate(ScoutBuilder $builder, $perPage, $page)
    {
        $this->searches[] = $builder;

        $response = $this->responses[$builder->definition->entityType] ?? ['hits' => [], 'total' => 0, 'facets' => []];

        return [
            'hits' => array_slice($response['hits'], ((int) $page - 1) * (int) $perPage, (int) $perPage),
            'total' => $response['total'],
            'facets' => array_intersect_key($response['facets'], array_flip($builder->facets)),
        ];
    }

    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('id')->values();
    }

    public function map(ScoutBuilder $builder, $results, $model)
    {
        $keys = $this->mapIds($results)->all();
        $positions = array_flip(array_map(strval(...), $keys));

        return $model->getScoutModelsByIds($builder, $keys)
            ->filter(static fn ($model): bool => isset($positions[(string) $model->getScoutKey()]))
            ->sortBy(static fn ($model): int => $positions[(string) $model->getScoutKey()])
            ->values();
    }

    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Assert a search passing the test was run.
     *
     * @param  (callable(Builder): bool)|null  $test
     */
    public function assertSearched(?callable $test = null): void
    {
        Assert::assertNotEmpty(
            array_filter($this->searches, $test ?? static fn (): bool => true),
            'The expected search was not run.',
        );
    }

    public function assertNothingSearched(): void
    {
        Assert::assertSame([], $this->searches, 'Searches were run unexpectedly.');
    }
}
