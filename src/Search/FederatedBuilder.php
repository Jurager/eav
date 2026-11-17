<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;
use Jurager\Eav\Contracts\Search\FederatesSearches;

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
        if (count(array_unique(array_map(static fn (Builder $search): string => $search->engine()::class, $searches))) > 1) {
            throw new InvalidArgumentException('The searches of a federated search must run on the same search engine.');
        }
    }

    /**
     * Run the searches and get a page of models of mixed types, with facets.
     *
     * The total is an estimate. Facets are keyed by entity type, then by facet name, and describe every hit
     * of that type.
     */
    public function paginate(?int $perPage = null, string $pageName = 'page', ?int $page = null): Results
    {
        $perPage ??= 15;
        $page = max(1, $page ?? Paginator::resolveCurrentPage($pageName));
        $offset = ($page - 1) * $perPage;

        $engine = $this->searches === [] ? null : $this->searches[0]->engine();

        [$models, $total, $facets] = match (true) {
            $engine === null => [[], 0, []],
            $engine instanceof FederatesSearches => $this->federate($engine, $perPage, $offset),
            default => $this->merge($perPage, $offset),
        };

        return Results::of(new LengthAwarePaginator(new EloquentCollection($models), $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]), $facets);
    }

    /**
     * Run the searches and get the models of the best hits, of mixed types.
     *
     * @return EloquentCollection<int, Model>
     */
    public function get(int $limit = 20): EloquentCollection
    {
        return $this->paginate($limit, 'page', 1)->getCollection();
    }

    /**
     * Let the engine merge the searches, then load the models as Scout loads them.
     *
     * @return array{0: list<Model>, 1: int, 2: array<string, mixed>}
     */
    private function federate(FederatesSearches $engine, int $limit, int $offset): array
    {
        $found = $engine->federated($this->searches, $limit, $offset);
        $loaded = [];

        foreach ($this->searches as $position => $search) {
            $keys = [];

            foreach ($found['hits'] as [$owner, $hit]) {
                if ($owner === $search) {
                    $keys[] = $hit[$search->model->getScoutKeyName()];
                }
            }

            $loaded[$position] = $keys === []
                ? []
                : $search->model->getScoutModelsByIds($search, $keys)->keyBy(static fn (Model $model): string => (string) $model->getScoutKey())->all();
        }

        $models = [];

        foreach ($found['hits'] as [$owner, $hit]) {
            $model = $loaded[array_search($owner, $this->searches, true)][(string) $hit[$owner->model->getScoutKeyName()]] ?? null;

            if ($model === null) {
                continue;
            }

            // As Scout's engines do: what the engine tells about a hit travels on the model.
            foreach ($hit as $key => $value) {
                if (str_starts_with((string) $key, '_')) {
                    $model->withScoutMetadata($key, $value);
                }
            }

            $models[] = $model;
        }

        $facets = [];

        foreach ($this->searches as $search) {
            if (isset($found['facets'][$search->definition->entityType])) {
                $facets[$search->definition->entityType] = $this->formatter->format($search, $found['facets'][$search->definition->entityType]);
            }
        }

        return [$models, $found['total'], $facets];
    }

    /**
     * Federate for an engine that cannot: run each search and rank the hits together by weight, and by
     * the engine's score where it tells one, by rank otherwise.
     *
     * @return array{0: list<Model>, 1: int, 2: array<string, mixed>}
     */
    private function merge(int $limit, int $offset): array
    {
        $ranked = [];
        $facets = [];
        $total = 0;

        foreach ($this->searches as $position => $search) {
            // Any of the searches may fill the whole page, so each is asked for all of it.
            $results = $search->paginate($offset + $limit, 'page', 1);
            $total += $results->total();

            foreach ($results->items() as $rank => $model) {
                $score = $model->scoutMetadata()['_rankingScore'] ?? 1 / ($rank + 1);

                $ranked[] = [$score * ($search->weight ?? 1.0), $position, $rank, $model];
            }

            if ($results->facets() !== []) {
                $facets[$search->definition->entityType] = $results->facets();
            }
        }

        // Best weighted score first; equal scores keep the order of the searches and of their own hits.
        usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        return [array_column(array_slice($ranked, $offset, $limit), 3), $total, $facets];
    }
}
