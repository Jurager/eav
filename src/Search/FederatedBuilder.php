<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\Paginator;
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Contracts\Search\FederatesSearches;

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
        private readonly FacetFormatter $formatter,
        private readonly array $searches,
    ) {}

    /**
     * Run the searches and get a page of models of mixed types, with hits and facets.
     *
     * The total is an estimate, and facets are keyed by entity type, then by facet name.
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Results
    {
        $page = max(1, $page ?? Paginator::resolveCurrentPage($pageName));
        $offset = ($page - 1) * $perPage;

        $found = $this->engine instanceof FederatesSearches
            ? $this->engine->federated($this->searches, $perPage, $offset)
            : $this->merge($perPage, $offset);

        $facets = [];

        foreach ($this->searches as $search) {
            if (isset($found->facets[$search->index->entityType])) {
                $facets[$search->index->entityType] = $this->formatter->format($search, $found->facets[$search->index->entityType]);
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
        return $this->paginate($limit, 1)->getCollection();
    }

    /**
     * Federate for an engine that cannot: run each search and rank the hits together by weighted score.
     */
    private function merge(int $limit, int $offset): Response
    {
        $ranked = [];
        $facets = [];
        $total = 0;

        foreach ($this->searches as $position => $search) {
            // Any of the searches may fill the whole page, so each is asked for all of it.
            $found = $search->run($offset + $limit);
            $total += $found->total;

            foreach ($found->hits as $rank => $hit) {
                $ranked[] = [($hit->score ?? 0.0) * ($search->weight ?? 1.0), $position, $rank, $hit];
            }

            if ($found->facets !== []) {
                $facets[$search->index->entityType] = $found->facets;
            }
        }

        // Best weighted score first; equal scores keep the order of the searches and of their own hits.
        usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        return new Response(array_column(array_slice($ranked, $offset, $limit), 3), $total, $facets);
    }

    /**
     * Load the models of each entity type with that type's own search builder.
     *
     * @param  list<Hit>  $hits
     * @return list<Hit>
     */
    private function load(array $hits): array
    {
        foreach ($this->searches as $search) {
            $search->load(array_values(array_filter(
                $hits,
                static fn (Hit $hit): bool => $hit->model === null && $hit->entityType === $search->index->entityType,
            )));
        }

        return $hits;
    }
}
