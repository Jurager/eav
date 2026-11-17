<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Testing;

use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Dialects\Dialect;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Lets every search be built against the fake engine and reads what it answers.
 */
class FakeDialect extends Dialect
{
    public function extended(): bool
    {
        return true;
    }

    public function where(Builder $search, Group $group): void {}

    public function orderBy(Builder $search, IndexField $field, string $direction): void {}

    public function hits(Builder $search, mixed $results): array
    {
        return array_map(static fn (array $hit): Hit => $search->hit(
            $hit['id'],
            array_filter($hit, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY),
            $hit['_formatted'] ?? [],
            isset($hit['_score']) ? (float) $hit['_score'] : null,
        ), array_values($results['hits'] ?? []));
    }

    public function facets(Builder $search, mixed $results): array
    {
        return $results['facets'] ?? [];
    }

    /**
     * The values prepared for the facet, matched by substring.
     */
    public function facetValues(Builder $search, string $name, IndexField $field, ?string $query): array
    {
        $values = $search->withoutFacets()->facets($name)->paginateRaw(1, 'page', 1)->items()['facets'][$name] ?? [];

        return $query === null || $query === ''
            ? $values
            : array_filter($values, static fn (int|string $value): bool => mb_stripos((string) $value, $query) !== false, ARRAY_FILTER_USE_KEY);
    }
}
