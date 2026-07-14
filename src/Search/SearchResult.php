<?php

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Jurager\Eav\Search\Facets\FacetContext;

/**
 * Value object returned by {@see Search::search()}.
 *
 * Holds raw search output. Call {@see paginate()} to hydrate Eloquent models.
 */
class SearchResult
{
    /**
     * @param  (int|string)[]  $ids  Hit IDs in Meilisearch relevance order.
     * @param  array<string, mixed>  $facets  Enriched facet distribution.
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $total,
        public readonly array $facets,
        private readonly ?FacetContext $context = null,
    ) {
    }

    public function context(): ?FacetContext
    {
        return $this->context;
    }

    /**
     * Hydrate Eloquent models and wrap them in a LengthAwarePaginator.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(string $modelClass, int $perPage, int $page): LengthAwarePaginator
    {
        $ids = $this->ids;

        if (! $ids) {
            return new LengthAwarePaginator(
                collect(),
                $this->total,
                $perPage,
                $page,
                ['path' => Paginator::resolveCurrentPath()],
            );
        }

        $keyName = (new $modelClass())->getKeyName();
        $stringIds = array_map('strval', $ids);

        $items = $modelClass::query()
            ->whereIn($keyName, $ids)
            ->get()
            ->sortBy(fn ($model) => array_search((string) $model->getKey(), $stringIds) ?: PHP_INT_MAX)
            ->values();

        // filter[included.*] scopes eager-loaded relations, not the result set itself —
        // apply it uniformly here regardless of whether these IDs came from Meilisearch
        // or a plain DB query. Duck-typed: no dependency on the package that provides it.
        foreach ($items as $item) {
            if (method_exists($item, 'loadFilteredRelations')) {
                $item->loadFilteredRelations();
            }
        }

        return new LengthAwarePaginator(
            $items,
            $this->total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
