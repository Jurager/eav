<?php

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Result of an {@see EavSearch} call.
 *
 * @template TModel of Model
 */
class EavSearchResult
{
    /**
     * @param  int[]  $ids    Hit IDs in Meilisearch relevance order.
     * @param  array<string, mixed>  $facets  Enriched facet distribution.
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $total,
        public readonly array $facets,
    ) {}

    /**
     * Hydrate Eloquent models and wrap them in a LengthAwarePaginator.
     *
     * @param  class-string<TModel>  $modelClass
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(string $modelClass, int $perPage, int $page): LengthAwarePaginator
    {
        $ids = $this->ids;

        $items = $ids
            ? $modelClass::query()
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn ($m) => array_search((string) $m->getKey(), array_map('strval', $ids)))
                ->values()
            : collect();

        return new LengthAwarePaginator(
            $items,
            $this->total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
