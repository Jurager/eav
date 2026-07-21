<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;


/**
 * Value object returned by {@see Builder::search()}.
 *
 * Holds raw search output. Call {@see paginate()} to hydrate Eloquent models.
 */
class Result
{
    /**
     * @param  (int|string)[]  $ids  Hit IDs in Meilisearch relevance order.
     * @param  array<string, mixed>  $facets  Enriched facet distribution.
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $total,
        public readonly array $facets,
    ) {
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
            ->sortBy(function ($model) use ($stringIds) {
                $index = array_search((string) $model->getKey(), $stringIds, true);

                return $index !== false ? $index : PHP_INT_MAX;
            })
            ->values();

        return new LengthAwarePaginator(
            $items,
            $this->total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
