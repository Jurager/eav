<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Contracts\FilterResolver;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Filterable\Parsing\FilterParser;
use Jurager\Filterable\Support\ParsedFilters;

/**
 * Fluent search query over the index of one entity type.
 */
class Builder
{
    /**
     * Comparison signs accepted by where(), and the filter operators they stand for.
     */
    private const array OPERATORS = [
        '=' => 'eq', '!=' => 'ne', '<>' => 'ne', '>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte',
    ];

    public ?string $query = null;

    /** @var list<ParsedFilters> Conditions that must all hold. */
    public array $filters = [];

    /** @var list<array{0: string, 1: string}> Sort fields and directions. */
    public array $orders = [];

    /** @var list<string> */
    public array $facets = [];

    /** @var list<string> Locale codes the search runs in. */
    private array $locales;

    /** @var list<string>|null Fields the text is matched against; every searchable field when null. */
    public ?array $searchOn = null;

    /** @var list<string>|null Fields to highlight matches in; empty for every searched field. */
    public ?array $highlight = null;

    public ?string $distinct = null;

    public ?float $weight = null;

    public ?ParsedFilters $partition = null;

    /**
     * Trashed entities to include: none (false), all alongside the rest (true), or only them (null).
     */
    public ?bool $trashed = false;

    public bool $partitionFirst = true;

    /** @var array<string, mixed> Engine parameters passed through as they are. */
    public array $options = [];

    /** @var list<string> */
    private array $with = [];

    /** @var (Closure(EloquentBuilder): mixed)|null */
    private ?Closure $loadUsing = null;

    /**
     * @param  iterable<FilterResolver>  $resolvers
     * @param  list<string>  $locales  Locale codes the search runs in unless told otherwise.
     */
    public function __construct(
        private readonly Engine $engine,
        public readonly Index $index,
        private readonly iterable $resolvers = [],
        array $locales = [],
    ) {
        $this->locales = $locales;
    }

    /**
     * Set the text to search for.
     */
    public function query(?string $query): static
    {
        $this->query = $query === '' ? null : $query;

        return $this;
    }

    /**
     * Add a condition: `where('brand', 'nike')`, `where('price', '>=', 100)`.
     */
    public function where(string $field, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }

        $alias = self::OPERATORS[(string) $operator] ?? throw InvalidSearchQueryException::unsupportedOperator($field, (string) $operator);

        return $this->condition($field, $alias, $value);
    }

    public function whereNot(string $field, mixed $value): static
    {
        return $this->condition($field, 'ne', $value);
    }

    /** @param  array<int, mixed>  $values */
    public function whereIn(string $field, array $values): static
    {
        return $this->condition($field, 'in', array_values($values));
    }

    /** @param  array<int, mixed>  $values */
    public function whereNotIn(string $field, array $values): static
    {
        return $this->condition($field, 'nin', array_values($values));
    }

    /** @param  array{0: mixed, 1: mixed}  $range */
    public function whereBetween(string $field, array $range): static
    {
        return $this->condition($field, 'between', array_values($range));
    }

    /** @param  array{0: mixed, 1: mixed}  $range */
    public function whereNotBetween(string $field, array $range): static
    {
        return $this->condition($field, 'not_between', array_values($range));
    }

    /**
     * Match documents holding no value in the field.
     */
    public function whereNull(string $field): static
    {
        return $this->condition($field, 'null', true);
    }

    /**
     * Match documents holding a value in the field.
     */
    public function whereNotNull(string $field): static
    {
        return $this->condition($field, 'not_null', true);
    }

    /**
     * Add JSON:API filters as sent in a request: `['brand' => 'nike', 'price' => ['gte' => 100]]`.
     *
     * @param  array<string, mixed>|null  $filter
     */
    public function filter(?array $filter): static
    {
        if ($filter !== null && $filter !== []) {
            $this->filters[] = $this->parse($filter);
        }

        return $this;
    }

    /**
     * Sort by a JSON:API sort string: `-price,name`. Relevance decides among equals.
     */
    public function sort(?string $sort): static
    {
        foreach (array_filter(array_map(trim(...), explode(',', (string) $sort)), static fn (string $part): bool => $part !== '') as $part) {
            str_starts_with($part, '-') ? $this->orderBy(substr($part, 1), 'desc') : $this->orderBy(ltrim($part, '+'));
        }

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $this->orders[] = [$field, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    public function orderByDesc(string $field): static
    {
        return $this->orderBy($field, 'desc');
    }

    /**
     * Lead the results with the documents matching a condition, the rest following.
     *
     * @param  array<string, mixed>  $condition  JSON:API filters.
     * @param  bool  $first  Whether the matches come first, or last.
     */
    public function partition(array $condition, bool $first = true): static
    {
        $this->partition = $this->parse($condition);
        $this->partitionFirst = $first;

        return $this;
    }

    /**
     * Compute facets for the given filterable fields.
     *
     * @param  list<string>|string  $facets
     */
    public function facets(array|string $facets): static
    {
        $this->facets = array_values(array_unique([...$this->facets, ...(array) $facets]));

        return $this;
    }

    /**
     * Search in the given locales instead of the current one.
     */
    public function locale(string ...$locales): static
    {
        if (($unknown = array_diff($locales, $this->index->locales())) !== []) {
            throw InvalidSearchQueryException::unknownLocale(implode(', ', $unknown));
        }

        $this->locales = array_values($locales);

        return $this;
    }

    /**
     * Match the text against these fields only.
     */
    public function searchOn(string ...$fields): static
    {
        $this->searchOn = array_values($fields);

        return $this;
    }

    /**
     * Highlight matched words in the given fields, or in every searched field.
     */
    public function highlight(string ...$fields): static
    {
        $this->highlight = array_values($fields);

        return $this;
    }

    /**
     * How query words must match: `last` (default), `all`, or `frequency`.
     */
    public function matching(string $strategy): static
    {
        return $this->options(['matchingStrategy' => $strategy]);
    }

    /**
     * Drop hits whose ranking score is below the threshold, between 0 and 1.
     */
    public function threshold(float $score): static
    {
        return $this->options(['rankingScoreThreshold' => $score]);
    }

    /**
     * Keep one hit per value of the field, for this search only.
     */
    public function distinct(string $field): static
    {
        $this->distinct = $field;

        return $this;
    }

    /**
     * Include trashed entities, where Scout keeps them in the index (`scout.soft_delete`).
     */
    public function withTrashed(): static
    {
        $this->trashed = true;

        return $this;
    }

    /**
     * Match trashed entities only, where Scout keeps them in the index (`scout.soft_delete`).
     */
    public function onlyTrashed(): static
    {
        $this->trashed = null;

        return $this;
    }

    /**
     * Weight of this search's hits within a federated search; 1 by default.
     */
    public function weight(float $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    /**
     * Pass engine search parameters through as they are, e.g. `['hybrid' => [...]]`.
     *
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): static
    {
        $this->options = [...$this->options, ...$options];

        return $this;
    }

    /**
     * Eager load relations on the models of the hits.
     */
    public function with(string ...$relations): static
    {
        $this->with = [...$this->with, ...$relations];

        return $this;
    }

    /**
     * Customize the query the models of the hits are loaded with.
     *
     * @param  Closure(EloquentBuilder): mixed  $callback
     */
    public function loadUsing(Closure $callback): static
    {
        $this->loadUsing = $callback;

        return $this;
    }

    /**
     * Run the search and get a page of models, with hits and facets.
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Results
    {
        $page = max(1, $page ?? Paginator::resolveCurrentPage($pageName));
        $found = $this->engine->search($this, $perPage, $page);

        return Results::make($this->load($found['hits']), $found['facets'], $found['total'], $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Run the search and get the models of the best hits.
     *
     * @return EloquentCollection<int, Model>
     */
    public function get(int $limit = 20): EloquentCollection
    {
        return $this->paginate($limit, 1)->getCollection();
    }

    public function first(): ?Model
    {
        return $this->get(1)->first();
    }

    /**
     * Run the search and get the keys of the best hits, without loading models.
     *
     * @return list<int|string>
     */
    public function keys(int $limit = 20): array
    {
        return array_map(static fn (Hit $hit): int|string => $hit->key, $this->engine->search($this->withoutFacets(), $limit, 1)['hits']);
    }

    /**
     * Number of matching documents.
     */
    public function count(): int
    {
        return $this->engine->search($this->withoutFacets(), 0, 1)['total'];
    }

    /**
     * Search the values of one facet — for facets with more values than a result carries.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function facetValues(string $facet, ?string $query = null): array
    {
        return $this->engine->facetValues($this, $facet, $query);
    }

    /**
     * Locale codes the search runs in.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    /**
     * Find an index field by the name a request refers to it.
     *
     * @throws InvalidSearchQueryException
     */
    public function field(string $name): IndexField
    {
        return $this->index->find($name) ?? throw InvalidSearchQueryException::unknownField($name);
    }

    /**
     * Find an index field that may be filtered on, and so used as a facet.
     *
     * @throws InvalidSearchQueryException
     */
    public function filterable(string $name): IndexField
    {
        $field = $this->field($name);

        return $field->filterable ? $field : throw InvalidSearchQueryException::notFilterable($name);
    }

    /**
     * The document path of a field, in the first locale searched for a localized one.
     */
    public function path(IndexField $field): string
    {
        return $field->pathFor($this->locales[0] ?? null);
    }

    /**
     * Whether a top-level condition constrains the field.
     */
    public function constrains(IndexField $field): bool
    {
        foreach ($this->filters as $parsed) {
            foreach (array_keys($parsed->filters) as $name) {
                if ($this->index->find((string) $name) === $field) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Attach their models to hits. A hit whose model is gone, or left out by the load query, keeps `model` null.
     *
     * @internal
     *
     * @param  list<Hit>  $hits
     * @return list<Hit>
     */
    public function load(array $hits): array
    {
        if ($hits === []) {
            return $hits;
        }

        $model = $this->index->model;
        $query = $model->newQuery()->whereIn($model->qualifyColumn($model->getScoutKeyName()), array_map(static fn (Hit $hit): int|string => $hit->key, $hits));

        if ($this->index->softDeletes && $this->trashed !== false) {
            $this->trashed ? $query->withTrashed() : $query->onlyTrashed();
        }

        if ($this->with !== []) {
            $query->with($this->with);
        }

        if ($this->loadUsing !== null) {
            ($this->loadUsing)($query);
        }

        $models = $query->get()->keyBy(static fn (Model $model): string => (string) $model->getScoutKey());

        foreach ($hits as $hit) {
            $hit->model = $models->get((string) $hit->key);
        }

        return $hits;
    }

    /**
     * The same search with no facets to compute.
     */
    private function withoutFacets(): static
    {
        $search = clone $this;
        $search->facets = [];

        return $search;
    }

    private function condition(string $field, string $operator, mixed $value): static
    {
        $this->filters[] = new ParsedFilters([$field => [$operator => $value]], [], [], [], []);

        return $this;
    }

    /**
     * Parse request filters, handing keys the index does not hold to the filter resolvers.
     *
     * @param  array<string, mixed>  $filter
     */
    private function parse(array $filter): ParsedFilters
    {
        $parsed = (new FilterParser)->parse($filter, []);

        return $parsed->withSanitized(
            filters: $this->substitute($parsed->filters),
            orGroups: array_map($this->substitute(...), $parsed->orGroups),
            andGroups: array_map($this->substitute(...), $parsed->andGroups),
        );
    }

    /**
     * Swap conditions on keys the index does not hold for what a resolver makes of them.
     *
     * @param  array<array-key, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function substitute(array $conditions): array
    {
        $resolved = [];

        foreach ($conditions as $name => $value) {
            $name = (string) $name;

            if ($this->index->find($name) === null) {
                foreach ($this->resolvers as $resolver) {
                    if (($substitute = $resolver->resolve($name, $value, $this->index->model)) !== null) {
                        [$name, $value] = $substitute;

                        break;
                    }
                }
            }

            $resolved[$name] = $value;
        }

        return $resolved;
    }
}
