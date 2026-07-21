<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Search\Contracts\FilterResolver;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Jurager\Filterable\Parsing\FilterParser;
use Jurager\Filterable\Support\ParsedFilters;
use Jurager\Eav\Search\Engine;

class Builder
{
    private ?string $query = null;

    private ParsedFilters $filter;

    /** @var array<string, string> */
    private array $map = [];

    /** @var array<int, string> */
    private array $facets = [];

    private ?Model $model = null;

    public function __construct(
        private readonly Engine $engine,
        private readonly iterable $resolvers,
        private readonly string $entityType
    ) {
        $this->filter = (new FilterParser())->parse([], []);

        $modelClass = Relation::getMorphedModel($entityType);
        $this->model = $modelClass ? new $modelClass() : null;
    }

    /** Set search query string. */
    public function query(?string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** Parse and resolve filters. */
    public function filter(array $filter): static
    {
        $parsed = (new FilterParser())->parse($filter, []);

        if ($this->model !== null) {
            $parsed = $parsed->withSanitized(
                filters:   $this->resolveFilters($parsed->filters, $this->model),
                orGroups:  $parsed->orGroups,
                andGroups: $parsed->andGroups,
            );
        }

        $this->filter = $parsed;

        return $this;
    }

    private function resolveFilters(array $filters, Model $model): array
    {
        $result = [];

        foreach ($filters as $key => $value) {
            $key = (string) $key;

            if (! str_contains($key, '.') || $this->directlyIndexed($key, $model)) {
                $result[$key] = $value;
                continue;
            }

            $resolved = null;

            foreach ($this->resolvers as $resolver) {
                /** @var FilterResolver $resolver */
                if (($resolved = $resolver->resolve($key, $value, $model)) !== null) {
                    break;
                }
            }

            [$resolvedKey, $resolvedValue] = $resolved ?? [$key, $value];

            $result[$resolvedKey] = $resolvedValue;
        }

        return $result;
    }

    /** Check if filter key is directly indexed. */
    private function directlyIndexed(string $key, Model $model): bool
    {
        return $model instanceof InteractsWithIndex && array_key_exists($key, $model->indexed());
    }

    /** Check if filter is active for key. */
    public function hasFilter(string $key): bool
    {
        return array_key_exists($key, $this->filter->filters);
    }

    /** Read numeric IDs from filter. */
    public function ids(string $key): array
    {
        $value = $this->filter->filters[$key] ?? null;

        if (is_array($value)) {
            $value = $value['in'] ?? $value['eq'] ?? $value;
        }

        if (blank($value)) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('intval', $items), static fn ($id) => $id > 0));
    }

    /** Set filter keys mapping. */
    public function map(array $map): static
    {
        $this->map = $map;

        return $this;
    }

    /** Set facets for computation. */
    public function facets(array $facets): static
    {
        $this->facets = $facets;

        return $this;
    }

    public function search(int $perPage = 15, int $page = 1): Result
    {
        return $this->engine->search($this, $perPage, $page);
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getModel(): ?Model
    {
        return $this->model;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function getFilter(): ParsedFilters
    {
        return $this->filter;
    }

    public function getFacets(): array
    {
        return $this->facets;
    }

    public function getMap(): array
    {
        return $this->map;
    }
}
