<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A page of search results: the models as a paginator, with the hits and facets alongside.
 *
 * @extends LengthAwarePaginator<int, \Illuminate\Database\Eloquent\Model>
 */
class Results extends LengthAwarePaginator
{
    /** @var list<Hit> */
    protected array $hits = [];

    /** @var array<string, mixed> */
    protected array $facets = [];

    /**
     * @param  list<Hit>  $hits
     * @param  array<string, mixed>  $facets
     * @param  array<string, mixed>  $options
     */
    public static function make(array $hits, array $facets, int $total, int $perPage, int $page, array $options = []): static
    {
        $models = [];

        foreach ($hits as $hit) {
            if ($hit->model !== null) {
                $models[] = $hit->model;
            }
        }

        $results = new static(new EloquentCollection($models), $total, max(1, $perPage), $page, $options);
        $results->hits = $hits;
        $results->facets = $facets;

        return $results;
    }

    /**
     * The hits behind the page: documents, highlights and ranking scores.
     *
     * @return Collection<int, Hit>
     */
    public function hits(): Collection
    {
        return new Collection($this->hits);
    }

    /**
     * Keys of the matched documents, in ranking order.
     *
     * @return list<int|string>
     */
    public function keys(): array
    {
        return array_map(static fn (Hit $hit): int|string => $hit->key, $this->hits);
    }

    /**
     * Facets keyed by the name they were asked for.
     *
     * A facet of values is `['type' => 'terms', 'values' => [['value', 'label', 'count'], ...]]`;
     * a numeric or date facet is `['type' => 'range', 'min' => ..., 'max' => ...]`.
     *
     * @return array<string, mixed>
     */
    public function facets(): array
    {
        return $this->facets;
    }

    /**
     * One facet, by name.
     *
     * @return array<string, mixed>|null
     */
    public function facet(string $name): ?array
    {
        return $this->facets[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...parent::toArray(), 'facets' => $this->facets];
    }
}
