<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A page of search results: Scout's paginator of models, with the facets alongside.
 *
 * What the engine tells about each hit — its ranking score, highlights — is on the model, as Scout's
 * metadata: `$model->scoutMetadata()`.
 *
 * @extends LengthAwarePaginator<int, \Illuminate\Database\Eloquent\Model>
 */
class Results extends LengthAwarePaginator
{
    /** @var array<string, mixed> */
    protected array $facets = [];

    /**
     * Carry a paginator's page over, with facets.
     *
     * @param  array<string, mixed>  $facets
     */
    public static function of(PaginatorContract $paginator, array $facets = []): static
    {
        $results = new static($paginator->getCollection(), $paginator->total(), max(1, $paginator->perPage()), $paginator->currentPage(), [
            'path' => $paginator->path(),
            'pageName' => $paginator->getPageName(),
        ]);

        $results->facets = $facets;

        return $results;
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
