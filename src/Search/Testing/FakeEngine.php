<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Testing;

use Jurager\Eav\Contracts\Search\BoostsMatches;
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Response;
use PHPUnit\Framework\Assert;

/**
 * Answers searches with prepared hits and records them, in place of a real engine.
 */
class FakeEngine implements BoostsMatches, Engine
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

    public function search(Builder $search, int $perPage, int $page): Response
    {
        $this->searches[] = $search;

        $response = $this->responses[$search->index->entityType] ?? ['hits' => [], 'total' => 0, 'facets' => []];

        $hits = array_map(static fn (array $hit): Hit => $search->hit(
            $hit['id'],
            array_filter($hit, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY),
            $hit['_formatted'] ?? [],
            isset($hit['_score']) ? (float) $hit['_score'] : null,
        ), array_slice($response['hits'], ($page - 1) * $perPage, $perPage));

        return new Response($hits, $response['total'], array_intersect_key($response['facets'], array_flip($search->facets)));
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
