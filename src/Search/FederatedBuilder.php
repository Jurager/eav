<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;

/**
 * Searches over several entity types run as one, their hits merged into a single list ranked across the types.
 */
class FederatedBuilder
{
    /**
     * @param  list<Builder>  $searches
     */
    public function __construct(
        private readonly FacetFormatter $formatter,
        private readonly array $searches,
    ) {
        $types = array_map(static fn (Builder $search): string => $search->definition->entityType, $searches);

        // Hits of one type form one list, with one set of facets: ranking some of them higher is a boost.
        if (count($types) !== count(array_unique($types))) {
            throw new InvalidArgumentException('A federated search takes one search per entity type. To rank some hits of a type higher, boost() its search.');
        }

        // One request can only go to one engine.
        if (count(array_unique(array_map(static fn (Builder $search): string => $search->dialect()::class, $searches))) > 1) {
            throw new InvalidArgumentException('The searches of a federated search must run on the same search engine.');
        }
    }

    /**
     * Run the searches and get a page of models of mixed types, with hits and facets.
     *
     * The total is an estimate. Facets are keyed by entity type, then by facet name, and describe every hit
     * of that type.
     */
    public function paginate(?int $perPage = null, string $pageName = 'page', ?int $page = null): Results
    {
        $perPage ??= 15;
        $page = max(1, $page ?? Paginator::resolveCurrentPage($pageName));

        $found = $this->searches === []
            ? new Response
            : $this->searches[0]->dialect()->federated($this->searches, $perPage, ($page - 1) * $perPage);

        $facets = $found->facets;

        if (! $found->formatted) {
            $facets = [];

            foreach ($this->searches as $search) {
                if (isset($found->facets[$search->definition->entityType])) {
                    $facets[$search->definition->entityType] = $this->formatter->format($search, $found->facets[$search->definition->entityType]);
                }
            }
        }

        return Results::make($this->load($found->hits), $facets, $found->total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Run the searches and get the models of the best hits, of mixed types.
     *
     * @return EloquentCollection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function get(int $limit = 20): EloquentCollection
    {
        return $this->paginate($limit, 'page', 1)->getCollection();
    }

    /**
     * Load the models of each search's hits as Scout loads them, with that search's own query callback.
     *
     * @param  list<Hit>  $hits
     * @return list<Hit>
     */
    private function load(array $hits): array
    {
        foreach ($this->searches as $search) {
            $own = array_filter($hits, static fn (Hit $hit): bool => $hit->model === null && $hit->search === $search);

            if ($own === []) {
                continue;
            }

            $models = $search->model
                ->getScoutModelsByIds($search, array_map(static fn (Hit $hit): int|string => $hit->key, array_values($own)))
                ->keyBy(static fn ($model): string => (string) $model->getScoutKey());

            foreach ($own as $hit) {
                $hit->model = $models->get((string) $hit->key);
            }
        }

        return $hits;
    }
}
