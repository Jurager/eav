<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Jurager\Eav\Contracts\Search\FilterResolver;
use Jurager\Eav\Contracts\Search\SupportsExtendedSearch;
use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Filters\Normalizer;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\Indexer;
use Jurager\Eav\Search\Index\IndexFactory;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Filterable\Parsing\FilterParser;
use Jurager\Filterable\Support\ParsedFilters;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Exceptions\NotSupportedException;
use LogicException;

/**
 * Scout's search builder for an entity with attributes: `Product::search('phone')`.
 *
 * Fields are named as the index definition names them and checked against it, and the builder adds what
 * Scout's query API lacks — request filters, facets, locales, boosts — on an engine that supports them.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends ScoutBuilder<TModel>
 */
class Builder extends ScoutBuilder
{
    /**
     * Comparison signs accepted by where(), and the filter operators they stand for.
     */
    private const array OPERATORS = [
        '=' => 'eq', '!=' => 'ne', '<>' => 'ne', '>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte',
    ];

    /**
     * The index definition of the entity searched.
     */
    public readonly Index $definition;

    /** @var list<Group> Checked conditions that must all hold. */
    public array $conditions = [];

    /** @var list<array{0: IndexField, 1: string}> Sortable fields and their directions. */
    public array $sorts = [];

    /** @var list<string> Names of the filterable fields facets are asked for. */
    public array $facets = [];

    /** @var list<string>|null Names of the searchable fields the text is matched against; all of them when null. */
    public ?array $searchOn = null;

    /** @var list<string>|null Fields to highlight matches in; empty for every searched field. */
    public ?array $highlight = null;

    /** Whether the engine is asked how it read the text, for `Results::correction()`. */
    public bool $correction = false;

    /** Whether a search that finds nothing is run again for the text typed another way. */
    public bool $retype = false;

    public ?string $distinct = null;

    public ?float $weight = null;

    public ?Group $partition = null;

    /**
     * Whether documents matching the partition come first.
     */
    public bool $partitionFirst = true;

    /** @var list<array{0: Group, 1: float}> Conditions whose matches rank higher, with the weight of each. */
    private array $boosts = [];

    /** @var list<string> Locale codes the search runs in. */
    private array $locales;

    /** @var list<FilterResolver> Resolvers for filter keys the index does not hold. */
    private readonly array $resolvers;

    public function __construct(
        IndexFactory $indexes,
        LocaleRegistry $locales,
        private readonly Indexer $indexer,
        private readonly FacetFormatter $formatter,
        $model,
        $query = '',
        $callback = null,
        $softDelete = false,
    ) {
        parent::__construct($model, $query, $callback, $softDelete);

        $this->definition = $indexes->for($model);
        $this->resolvers = array_map(app(...), (array) config('eav.search.resolvers', []));

        try {
            $current = $locales->code($locales->current());
        } catch (InvalidConfigurationException) {
            // No locales are set up: nothing is localized either.
            $current = null;
        }

        $this->locales = $current === null ? [] : [$current];
    }

    /**
     * Add a condition: `where('brand', 'nike')`, `where('price', '>=', 100)`.
     *
     * @param  string  $field
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    public function where($field, $operator, $value = null): static
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

    /**
     * @param  string  $field
     * @param  Arrayable<array-key, mixed>|array<int, mixed>  $values
     */
    public function whereIn($field, $values): static
    {
        return $this->condition($field, 'in', array_values($values instanceof Arrayable ? $values->toArray() : $values));
    }

    /**
     * @param  string  $field
     * @param  Arrayable<array-key, mixed>|array<int, mixed>  $values
     */
    public function whereNotIn($field, $values): static
    {
        return $this->condition($field, 'nin', array_values($values instanceof Arrayable ? $values->toArray() : $values));
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
     * Customize the query the models of the hits are loaded with. The text to search for goes to `search()`.
     *
     * @param  callable  $callback
     */
    public function query($callback): static
    {
        if (is_string($callback) && ! is_callable($callback)) {
            throw new InvalidArgumentException('query() customizes how models are loaded. Pass the text to search(): Model::search($text).');
        }

        return parent::query($callback);
    }

    /**
     * Add JSON:API filters as sent in a request: `['brand' => 'nike', 'price' => ['gte' => 100]]`.
     *
     * @param  array<string, mixed>|null  $filter
     */
    public function filter(?array $filter): static
    {
        if ($filter !== null && $filter !== [] && ! ($group = $this->parse($filter))->isEmpty()) {
            $this->add($group);
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

    /**
     * @param  string  $column
     * @param  string  $direction
     */
    public function orderBy($column, $direction = 'asc'): static
    {
        $field = $this->field($column);

        if (! $field->sortable) {
            throw InvalidSearchQueryException::notSortable($column);
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $this->sorts[] = [$field, $direction];

        // An engine that only knows Scout's query API reads the order off Scout's own list.
        if (! $this->extended()) {
            $this->orders[] = ['column' => $this->path($field), 'direction' => $direction];
        }

        return $this;
    }

    /**
     * Lead the results with the documents matching a condition, the rest following.
     *
     * @param  array<string, mixed>  $condition  JSON:API filters.
     * @param  bool  $first  Whether the matches come first, or last.
     */
    public function partition(array $condition, bool $first = true): static
    {
        $this->requireExtended('partition results');

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
        $this->requireExtended('boost matches');

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
        return $this->text() !== null && $this->sorts === [] ? $this->boosts : [];
    }

    /**
     * Compute facets for the given filterable fields.
     *
     * @param  list<string>|string  $facets
     */
    public function facets(array|string $facets): static
    {
        $this->requireExtended('compute facets');

        array_map($this->filterable(...), (array) $facets);

        $this->facets = array_values(array_unique([...$this->facets, ...(array) $facets]));

        return $this;
    }

    /**
     * Search in the given locales instead of the current one.
     */
    public function locale(string ...$locales): static
    {
        if (($unknown = array_diff($locales, $this->definition->locales())) !== []) {
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
        $this->requireExtended('search chosen fields');

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
        $this->requireExtended('highlight matches');

        array_map($this->field(...), $fields);

        $this->highlight = array_values($fields);

        return $this;
    }

    /**
     * Report how the engine read the text: the typos it put up with, the words it skipped.
     */
    public function correction(): static
    {
        $this->requireExtended('report corrections');

        $this->correction = true;

        return $this;
    }

    /**
     * Search the text typed in the other keyboard layout or script when nothing is found.
     */
    public function retype(): static
    {
        $this->retype = true;

        return $this;
    }

    /**
     * The same search that is not run again for a retyped text.
     */
    public function withoutRetype(): static
    {
        $search = clone $this;
        $search->retype = false;

        return $search;
    }

    /**
     * The same search for another text.
     */
    public function withText(string $text): static
    {
        $search = clone $this;
        $search->query = $text;

        return $search;
    }

    /**
     * Keep one hit per value of the field, for this search only.
     */
    public function distinct(string $field): static
    {
        $this->requireExtended('keep one hit per value');

        $this->filterable($field);

        $this->distinct = $field;

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
     * Run the search and get a page of models, with the facets asked for.
     *
     * @param  int|null  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     */
    public function paginate($perPage = null, $pageName = 'page', $page = null): Results
    {
        [$paginator, $raw] = $this->capturing(fn () => parent::paginate($perPage, $pageName, $page));

        $engine = $this->engine();

        // A document without a model may be left behind by a deleted entity: clean those up.
        if ($raw !== null && config('eav.search.prune', true)) {
            $this->indexer->prune($this->model::class, array_values(array_diff(
                $engine->mapIds($raw)->all(),
                $paginator->getCollection()->map->getScoutKey()->all(),
            )));
        }

        $results = Results::of(
            $paginator,
            $engine instanceof SupportsExtendedSearch ? $this->formatter->format($this, $engine->facets($this, $raw)) : [],
            $this->corrected($paginator),
        )->appends('query', $this->query);

        if ($results->total() > 0) {
            return $results;
        }

        return $this->retyped(fn (string $text): Results => $this->withoutRetype()->withText($text)->paginate($perPage, $pageName, $page))?->appends('query', $this->query) ?? $results;
    }

    /**
     * The results of the first retyped text that finds something; null when none does, or when retyping was not asked for.
     *
     * @param  Closure(string): Results  $search
     */
    public function retyped(Closure $search): ?Results
    {
        $text = $this->text();

        if (! $this->retype || $text === null) {
            return null;
        }

        return Retype::find($text, strtolower(substr($this->locales()[0] ?? 'en', 0, 2)), (array) config('eav.search.layouts', []), $search);
    }

    /**
     * How the engine read the text, judged by the best hit of the whole list.
     */
    private function corrected(LengthAwarePaginator $page): ?Correction
    {
        if (! $this->corrects()) {
            return null;
        }

        $best = $page->currentPage() === 1 ? $page->getCollection()->first() : $this->withoutFacets()->take(1)->first();

        return $best === null ? null : Correction::detect((string) $this->text(), $best->scoutMetadata());
    }

    /**
     * Number of matching documents.
     */
    public function count(): int
    {
        $search = $this->withoutFacets()->take(0);

        return (int) $search->engine()->getTotalCount($search->raw());
    }

    /**
     * Search the values of one facet — for facets with more values than a result carries.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function facetValues(string $facet, ?string $query = null): array
    {
        $this->requireExtended('search facet values');

        $field = $this->filterable($facet);
        $engine = $this->engine();

        return $engine instanceof SupportsExtendedSearch
            ? $this->formatter->terms($this, $field, $engine->facetValues($this, $facet, $field, $query))
            : [];
    }

    /**
     * The text searched for, or null when there is none.
     */
    public function text(): ?string
    {
        return $this->query === null || $this->query === '' ? null : (string) $this->query;
    }

    /**
     * Whether a correction of the text is asked for: it has no meaning without a text.
     */
    public function corrects(): bool
    {
        return $this->correction && $this->text() !== null;
    }

    /**
     * Name of the index searched.
     */
    public function uid(): string
    {
        return $this->index ?: $this->definition->uid();
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
     * The Scout engine the search runs on.
     *
     * @return Engine
     */
    public function engine()
    {
        return parent::engine();
    }

    /**
     * Find an index field by the name a request refers to it.
     *
     * @throws InvalidSearchQueryException
     */
    public function field(string $name): IndexField
    {
        return $this->definition->find($name) ?? throw InvalidSearchQueryException::unknownField($name);
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
        return array_any($this->conditions, static fn (Group $group): bool => $group->constrains($field));
    }

    /**
     * The same search with no facets to compute.
     */
    public function withoutFacets(): static
    {
        $search = clone $this;
        $search->facets = [];

        return $search;
    }

    /**
     * Run a Scout terminal and keep the engine's raw results alongside what it returned.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $terminal
     * @return array{0: TResult, 1: mixed}
     */
    private function capturing(callable $terminal): array
    {
        $raw = null;
        $after = $this->afterRawSearchCallback;

        $this->afterRawSearchCallback = static function (mixed $results) use (&$raw, $after): mixed {
            $results = $after !== null ? ($after($results) ?: $results) : $results;

            return $raw = $results;
        };

        try {
            return [$terminal(), $raw];
        } finally {
            $this->afterRawSearchCallback = $after;
        }
    }

    /**
     * Whether the engine answers more than Scout's query API can ask.
     */
    private function extended(): bool
    {
        // Scout's null driver finds nothing whatever it is asked, so it can be asked anything: an
        // application under test builds its searches the way it does in production.
        return $this->engine() instanceof SupportsExtendedSearch || $this->engine() instanceof NullEngine;
    }

    /**
     * Refuse what Scout's query API cannot express on an engine that knows nothing more.
     */
    private function requireExtended(string $ability): void
    {
        if (! $this->extended()) {
            throw new NotSupportedException("The search engine in use cannot {$ability}.");
        }
    }

    /**
     * Take checked conditions; an engine that only knows Scout's query API gets them as Scout's own wheres.
     */
    private function add(Group $group): void
    {
        $this->conditions[] = $group;

        if (! $this->extended()) {
            $this->native($group);
        }
    }

    /**
     * Express conditions with Scout's own wheres.
     *
     * @throws NotSupportedException When Scout's query API cannot express them.
     */
    private function native(Group $group): void
    {
        if ($group->any && count($group->members) > 1) {
            throw new NotSupportedException('The search engine in use cannot match any of several conditions.');
        }

        foreach ($group->members as $member) {
            if ($member instanceof Group) {
                $this->native($member);

                continue;
            }

            $path = $this->path($member->field);
            $where = static fn (string $operator, mixed $value): array => ['field' => $path, 'operator' => $operator, 'value' => $value];

            match ($member->comparison) {
                Comparison::Equal => $this->wheres[] = $where('=', $member->value),
                Comparison::NotEqual => $this->wheres[] = $where('!=', $member->value),
                Comparison::Greater => $this->wheres[] = $where('>', $member->value),
                Comparison::GreaterOrEqual => $this->wheres[] = $where('>=', $member->value),
                Comparison::Less => $this->wheres[] = $where('<', $member->value),
                Comparison::LessOrEqual => $this->wheres[] = $where('<=', $member->value),
                Comparison::In => $this->whereIns[$path] = $member->value,
                Comparison::NotIn => $this->whereNotIns[$path] = $member->value,
                Comparison::Between => array_push($this->wheres, $where('>=', $member->value[0]), $where('<=', $member->value[1])),
                Comparison::Missing => $this->wheres[] = $where('=', null),
                Comparison::Exists => $this->wheres[] = $where('!=', null),
                default => throw new NotSupportedException("The search engine in use cannot compare with [{$member->comparison->value}]."),
            };
        }
    }

    private function condition(string $field, string $operator, mixed $value): static
    {
        $this->add($this->normalize(new ParsedFilters([$field => [$operator => $value]], [], [], [], [])));

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
        return (new Normalizer($this->definition))->normalize($parsed);
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

            if ($this->definition->find($name) === null) {
                foreach ($this->resolvers as $resolver) {
                    if (($substitute = $resolver->resolve($name, $value, $this->model)) !== null) {
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
