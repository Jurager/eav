<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jurager\Eav\Search\Contracts\FilterResolver;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;
use Jurager\Filterable\Parsing\FilterParser;
use Jurager\Filterable\Support\ParsedFilters;

/** Build search queries for EAV entities. */
class Builder
{
    /**
     * Current search query string.
     *
     * @var string|null
     */
    private ?string $query = null;

    /**
     * Parsed filters.
     *
     * @var ParsedFilters
     */
    private ParsedFilters $filter;

    /**
     * Map of filter keys to indexed fields.
     *
     * @var array<string, string>
     */
    private array $map = [];

    /**
     * Configured search facets.
     *
     * @var array<int, string>
     */
    private array $facets = [];

    /**
     * Eloquent model instance for the entity type.
     *
     * @var Model|null
     */
    private ?Model $model = null;

    /**
     * @param iterable<FilterResolver> $resolvers
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly iterable $resolvers,
        private readonly string $entityType,
    ) {
        $this->filter = (new FilterParser())->parse([], []);

        $model = Relation::getMorphedModel($entityType) ?? $entityType;

        if (class_exists($model)) {
            $this->model = new $model();
        }
    }

    /** Set search query string. */
    public function query(?string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** Parse and resolve JSON:API filters. */
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

    /** Resolve filters unhandled by search index. */
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
        $result = [];

        foreach ($items as $item) {
            $id = (int) $item;

            if ($id > 0) {
                $result[] = $id;
            }
        }

        return $result;
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

    /** Execute search via engine. */
    public function search(int $perPage = 15, int $page = 1): Result
    {
        return $this->engine->search($this, $perPage, $page);
    }

    /** Get entity type. */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /** Get Eloquent model instance. */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /** Get current search query. */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    /** Get parsed filters. */
    public function getFilter(): ParsedFilters
    {
        return $this->filter;
    }

    /** Get configured facets. */
    public function getFacets(): array
    {
        return $this->facets;
    }

    /** Get filter map. */
    public function getMap(): array
    {
        return $this->map;
    }
}