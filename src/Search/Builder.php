<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Closure;
use InvalidArgumentException;
use LogicException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Jurager\Eav\Contracts\Search\BoostsMatches;
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Contracts\Search\FilterResolver;
use Jurager\Eav\Contracts\Search\SearchesFacetValues;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Filters\Normalizer;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\Indexer;
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

    /** @var list<Group> Checked conditions that must all hold. */
    public array $filters = [];

    /** @var list<array{0: IndexField, 1: string}> Sortable fields and their directions. */
    public array $orders = [];

    /** @var list<string> Names of the filterable fields facets are asked for. */
    public array $facets = [];

    /** @var list<string> Locale codes the search runs in. */
    private array $locales;

    /** @var list<string>|null Names of the searchable fields the text is matched against; all of them when null. */
    public ?array $searchOn = null;

    /** @var list<string>|null Fields to highlight matches in; empty for every searched field. */
    public ?array $highlight = null;

    public ?string $distinct = null;

    public ?float $weight = null;

    public ?Group $partition = null;

    /** @var list<array{0: Group, 1: float}> Conditions whose matches rank higher, with the weight of each. */
    private array $boosts = [];

    /**
     * Trashed entities to include: none (false), all alongside the rest (true), or only them (null).
     */
    public ?bool $trashed = false;

    public bool $partitionFirst = true;

    /**
     * How query words must match, in the engine's terms; its default when null.
     */
    public ?string $matching = null;

    /**
     * Relevance, between 0 and 1, below which hits are dropped.
     */
    public ?float $threshold = null;

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
        private readonly Indexer $indexer,
        private readonly FacetFormatter $formatter,
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

        // As in Eloquent, comparing with null asks whether the field holds a value at all.
        if ($value === null && in_array($alias, ['eq', 'ne'], true)) {
            return $alias === 'eq' ? $this->whereNull($field) : $this->whereNotNull($field);
        }

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
        if ($filter !== null && $filter !== [] && ! ($group = $this->parse($filter))->isEmpty()) {
            $this->filters[] = $group;
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
        $sortable = $this->field($field);

        if (! $sortable->sortable) {
            throw InvalidSearchQueryException::notSortable($field);
        }

        $this->orders[] = [$sortable, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

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
        if ($this->boosts !== []) {
            throw new LogicException('A search cannot be both partitioned and boosted.');
        }

        $partition = $this->parse($condition);

        // Nothing to split on leaves the results in one piece.
        $this->partition = $partition->isEmpty() ? null : $partition;
        $this->partitionFirst = $first;

        return $this;
    }

    /**
     * Rank the hits matching a condition higher: their relevance is multiplied by the weight.
     *
     * A boost acts on relevance, so it has no effect without a text query or when the search is sorted;
     * use `partition()` to lead with a group whatever the order.
     *
     * @param  array<string, mixed>  $condition  JSON:API filters.
     */
    public function boost(array $condition, float $weight = 2.0): static
    {
        if ($weight <= 0) {
            throw new InvalidArgumentException('The weight of a boost must be greater than zero.');
        }

        if ($this->partition !== null) {
            throw new LogicException('A search cannot be both partitioned and boosted.');
        }

        if (! ($group = $this->parse($condition))->isEmpty()) {
            $this->boosts[] = [$group, $weight];
        }

        return $this;
    }

    /**
     * The boosts in effect: none without a text query or with an explicit order, where relevance does not rank.
     *
     * @return list<array{0: Group, 1: float}>
     */
    public function boosts(): array
    {
        return $this->query !== null && $this->orders === [] ? $this->boosts : [];
    }

    /**
     * Compute facets for the given filterable fields.
     *
     * @param  list<string>|string  $facets
     */
    public function facets(array|string $facets): static
    {
        array_map($this->filterable(...), (array) $facets);

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
        foreach ($fields as $name) {
            if (! $this->field($name)->searchable) {
                throw InvalidSearchQueryException::notSearchable($name);
            }
        }

        $this->searchOn = array_values($fields);

        return $this;
    }

    /**
     * Highlight matched words in the given fields, or in every searched field.
     */
    public function highlight(string ...$fields): static
    {
        array_map($this->field(...), $fields);

        $this->highlight = array_values($fields);

        return $this;
    }

    /**
     * How query words must match, as the engine names it — `last`, `all` or `frequency` with Meilisearch.
     */
    public function matching(string $strategy): static
    {
        $this->matching = $strategy;

        return $this;
    }

    /**
     * Drop hits whose relevance is below the threshold, between 0 and 1.
     */
    public function threshold(float $score): static
    {
        $this->threshold = $score;

        return $this;
    }

    /**
     * Keep one hit per value of the field, for this search only.
     */
    public function distinct(string $field): static
    {
        $this->filterable($field);

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
     * Pass search parameters of the engine in use through as they are, e.g. `['hybrid' => [...]]` with Meilisearch.
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
        $found = $this->execute($perPage, $page);

        return Results::make($this->load($found->hits), $this->formatter->format($this, $found->facets), $found->total, $perPage, $page, [
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
        return array_map(static fn (Hit $hit): int|string => $hit->key, $this->withoutFacets()->execute($limit, 1)->hits);
    }

    /**
     * Number of matching documents.
     */
    public function count(): int
    {
        return $this->withoutFacets()->execute(0, 1)->total;
    }

    /**
     * Search the values of one facet — for facets with more values than a result carries.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function facetValues(string $facet, ?string $query = null): array
    {
        $field = $this->filterable($facet);

        if ($this->engine instanceof SearchesFacetValues) {
            return $this->formatter->terms($this, $field, $this->engine->facetValues($this, $field, $query));
        }

        // The engine cannot search a facet: match the values a regular search returns for it.
        $search = $this->withoutFacets()->facets($facet);
        $values = $search->execute(0, 1)->facets[$facet] ?? [];

        if ($query !== null && $query !== '') {
            $values = array_filter($values, static fn (int|string $value): bool => mb_stripos((string) $value, $query) !== false, ARRAY_FILTER_USE_KEY);
        }

        return $this->formatter->terms($this, $field, $values);
    }

    /**
     * Run the search for a federated search, which merges and loads the hits itself.
     *
     * @internal
     */
    public function run(int $limit): Response
    {
        return $this->execute($limit, 1);
    }

    /**
     * Ask the engine, covering for it where it cannot rank boosted matches itself.
     */
    private function execute(int $perPage, int $page): Response
    {
        $boosts = $this->boosts();

        if ($boosts === [] || $perPage <= 0 || $this->engine instanceof BoostsMatches) {
            return $this->engine->search($this, $perPage, $page);
        }

        // Every candidate for the page: the best hits as they are, and the best of each boosted condition.
        $size = $perPage * $page;
        $base = $this->engine->search($this->narrowed(), $size, 1);
        $ranked = [];

        foreach ($base->hits as $rank => $hit) {
            $ranked[(string) $hit->key] = [$hit->score ?? 0.0, $rank, $hit];
        }

        foreach ($boosts as [$condition, $weight]) {
            foreach ($this->engine->search($this->narrowed($condition), $size, 1)->hits as $hit) {
                $key = (string) $hit->key;
                $score = ($hit->score ?? 0.0) * $weight;

                if ($score > ($ranked[$key][0] ?? -1.0)) {
                    $ranked[$key] = [$score, $ranked[$key][1] ?? PHP_INT_MAX, $hit];
                }
            }
        }

        // Best weighted score first; equal scores keep the engine's own order.
        usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return new Response(
            array_map(
                fn (array $entry): Hit => $this->hit($entry[2]->key, $entry[2]->document, $entry[2]->formatted, $entry[0]),
                array_slice($ranked, ($page - 1) * $perPage, $perPage),
            ),
            $base->total,
            $base->facets,
        );
    }

    /**
     * The same search without boosts — as it is, or narrowed to one boosted condition, which needs no facets.
     */
    private function narrowed(?Group $condition = null): static
    {
        $search = clone $this;
        $search->boosts = [];

        if ($condition !== null) {
            $search->filters[] = $condition;
            $search->facets = [];
        }

        return $search;
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
        return array_any($this->filters, static fn (Group $group): bool => $group->constrains($field));
    }

    /**
     * Make a hit of this search — what an engine returns for each document it found.
     *
     * @param  array<string, mixed>  $document  The document as indexed.
     * @param  array<string, mixed>  $formatted  The document with matches highlighted, if the engine highlights.
     */
    public function hit(int|string $key, array $document = [], array $formatted = [], ?float $score = null): Hit
    {
        return new Hit($key, $this->index->entityType, $document, $formatted, $score, $this);
    }

    /**
     * Whether the hit was made by this search.
     */
    public function made(Hit $hit): bool
    {
        return $hit->search === $this;
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

        $missing = [];

        foreach ($hits as $hit) {
            if (($hit->model = $models->get((string) $hit->key)) === null) {
                $missing[] = $hit->key;
            }
        }

        // A hit without a model may be a document left behind by a deleted entity: clean those up.
        if (config('eav.search.prune', true)) {
            $this->indexer->prune($this->index->model::class, $missing);
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
        $this->filters[] = $this->normalize(new ParsedFilters([$field => [$operator => $value]], [], [], [], []));

        return $this;
    }

    /**
     * Parse request filters into checked conditions, handing keys the index does not hold to the filter resolvers.
     *
     * @param  array<string, mixed>  $filter
     */
    private function parse(array $filter): Group
    {
        $parsed = (new FilterParser)->parse($filter, []);

        return $this->normalize($parsed->withSanitized(
            filters: $this->substitute($parsed->filters),
            orGroups: array_map($this->substitute(...), $parsed->orGroups),
            andGroups: array_map($this->substitute(...), $parsed->andGroups),
        ));
    }

    /**
     * Check conditions against the index and cast their values, so every engine gets them alike.
     */
    private function normalize(ParsedFilters $parsed): Group
    {
        return (new Normalizer($this->index))->normalize($parsed);
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
