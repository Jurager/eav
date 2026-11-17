<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Several searches run as one, their hits merged into a single list ranked across entity types.
 */
class FederatedBuilder
{
    /**
     * @param  list<Builder>  $searches
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly array $searches,
    ) {}

    /**
     * Run the searches and get a page of models of mixed types, with hits and facets.
     *
     * The total is the engine's estimate, and facets are keyed by entity type, then by facet name.
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Results
    {
        $page = max(1, $page ?? Paginator::resolveCurrentPage($pageName));
        $found = $this->engine->federated($this->searches, $perPage, ($page - 1) * $perPage);

        return Results::make($this->models($found['hits']), $found['facets'], $found['total'], $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Run the searches and get the models of the best hits, of mixed types.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function get(int $limit = 20): Collection
    {
        return $this->paginate($limit, 1)->getCollection();
    }

    /**
     * Load the models of each entity type with that type's own search builder.
     *
     * @param  list<Hit>  $hits
     * @return list<Hit>
     */
    private function models(array $hits): array
    {
        foreach ($this->searches as $search) {
            $search->models(array_values(array_filter(
                $hits,
                static fn (Hit $hit): bool => $hit->model === null && $hit->entityType === $search->index->entityType,
            )));
        }

        return $hits;
    }
}
