<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class Result
{
    /**
     * @param array<int, int|string> $ids
     * @param array<string, mixed>   $facets
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $total,
        public readonly array $facets,
    ) {
    }

    /**
     * Hydrate Eloquent models into a paginator.
     *
     * @template TModel of Model
     *
     * @param class-string<TModel> $model
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(string $model, int $limit, int $page): LengthAwarePaginator
    {
        if (empty($this->ids)) {
            return $this->paginator(collect(), $limit, $page);
        }

        $key = (new $model())->getKeyName();

        $order = array_flip(array_map('strval', $this->ids));

        $items = $model::query()
            ->whereIn($key, $this->ids)
            ->get()
            ->sortBy(fn (Model $item): int => $order[(string) $item->getKey()] ?? PHP_INT_MAX)
            ->values();

        return $this->paginator($items, $limit, $page);
    }

    /** Create paginator instance. */
    private function paginator(Collection $items, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $this->total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
    }
}