<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A page of search results: Scout's paginator of models, with the facets and the correction of the text alongside.
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

    protected ?Correction $correction = null;

    /**
     * Carry a paginator's page over, with facets and the correction of the text.
     *
     * @param  array<string, mixed>  $facets
     */
    public static function of(PaginatorContract $paginator, array $facets = [], ?Correction $correction = null): static
    {
        $results = new static($paginator->getCollection(), $paginator->total(), max(1, $paginator->perPage()), $paginator->currentPage(), [
            'path' => $paginator->path(),
            'pageName' => $paginator->getPageName(),
        ]);

        $results->facets = $facets;
        $results->correction = $correction;

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
     * How the engine read the text when `correction()` was asked for; null when it was found as written.
     */
    public function correction(): ?Correction
    {
        return $this->correction;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'facets' => $this->facets,
            ...($this->correction === null ? [] : ['correction' => $this->correction->toArray()]),
        ];
    }
}
