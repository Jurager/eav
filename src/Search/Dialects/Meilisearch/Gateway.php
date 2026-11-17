<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Dialects\Meilisearch;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Meilisearch\Client;
use Meilisearch\Contracts\FacetSearchQuery;
use Meilisearch\Contracts\MultiSearchFederation;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Talks to Meilisearch through the client Scout is configured with, in plain request and response arrays.
 */
class Gateway
{
    public function __construct(private readonly Client $client) {}

    /**
     * Run several searches in one round trip, merged into one result list when a federation is given.
     *
     * @param  list<array<string, mixed>>  $queries
     * @param  array<string, mixed>|null  $federation
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException When Meilisearch rejects a request.
     */
    public function multiSearch(array $queries, ?array $federation = null): array
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

        return $this->send(fn (): array => $this->client->multiSearch($queries, $federation));
    }

    /**
     * Search the values of one facet.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException When Meilisearch rejects the request.
     */
    public function facetSearch(string $index, array $query): array
    {
        $query = new class($query) extends FacetSearchQuery
        {
            public function __construct(private readonly array $payload) {}

            public function toArray(): array
            {
                return $this->payload;
            }
        };

        return $this->send(fn (): array => $this->client->index($index)->facetSearch($query)->toArray());
    }

    /**
     * Update the settings of an index.
     *
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $index, array $settings): void
    {
        $this->client->index($index)->updateSettings($settings);
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
