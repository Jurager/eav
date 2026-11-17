<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;

/**
 * The calls the package makes to the search engine, as plain request and response arrays.
 */
interface Transport
{
    /**
     * Run several searches in one round trip, merged into one result list when a federation is given.
     *
     * @param  list<array<string, mixed>>  $queries
     * @param  array<string, mixed>|null  $federation
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException When the engine rejects a request.
     */
    public function multiSearch(array $queries, ?array $federation = null): array;

    /**
     * Search the values of one facet.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException When the engine rejects the request.
     */
    public function facetSearch(string $index, array $query): array;

    /**
     * Update the settings of an index.
     *
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(string $index, array $settings): void;
}
