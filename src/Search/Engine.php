<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Contracts\Transport;
use Jurager\Eav\Search\Filter\Compiler;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Eav\Search\Index\Settings;

/**
 * Turn search builders into engine requests, and engine responses into hits and facets.
 */
class Engine
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Compiler $compiler,
        private readonly Settings $settings,
        private readonly FieldFactory $fields,
        private readonly LocaleRegistry $locales,
    ) {}

    /**
     * Run a search.
     *
     * @return array{hits: list<Hit>, total: int, facets: array<string, mixed>}
     *
     * @throws InvalidSearchQueryException
     */
    public function search(Builder $builder, int $perPage, int $page): array
    {
        $partitioned = $builder->partition !== null;
        $facets = $this->facetFields($builder);

        // With a partition the main request only counts and facets; the hits come from each side in turn.
        $requests = ['main' => [
            ...$this->request($builder),
            'hitsPerPage' => $partitioned ? 0 : $perPage,
            'page' => $partitioned ? 1 : $page,
            ...($facets === [] ? [] : ['facets' => array_values(array_map(fn (IndexField $field): string => $this->path($builder, $field), $facets))]),
        ]];

        if ($partitioned) {
            $requests['leading'] = [...$this->request($builder, side: $builder->partitionFirst), 'hitsPerPage' => $perPage, 'page' => $page];
        }

        // A facet counts as if its own filter were not applied, so its other values stay selectable.
        foreach ($facets as $name => $field) {
            if ($this->constrained($builder, $field)) {
                $requests["facet:{$name}"] = [
                    ...$this->request($builder, except: [$field->path]),
                    'limit' => 0,
                    'facets' => [$this->path($builder, $field)],
                ];
            }
        }

        $responses = array_combine(array_keys($requests), $this->transport->multiSearch(array_values($requests))['results']);
        $main = $responses['main'];
        $hits = $main['hits'];

        if ($partitioned) {
            $hits = $this->partitionHits($builder, $responses['leading'], $perPage, $page);
        }

        return [
            'hits' => array_map(fn (array $hit): Hit => $this->hit($builder, $hit), $hits),
            'total' => (int) ($main['totalHits'] ?? $main['estimatedTotalHits'] ?? 0),
            'facets' => $this->facets($builder, $facets, $responses),
        ];
    }

    /**
     * Run several searches as one, their hits merged into a single ranked list.
     *
     * @param  list<Builder>  $builders
     * @return array{hits: list<Hit>, total: int, facets: array<string, array<string, mixed>>}
     *
     * @throws InvalidSearchQueryException
     */
    public function federated(array $builders, int $limit, int $offset): array
    {
        $queries = [];
        $facetFields = [];
        $federation = ['limit' => $limit, 'offset' => $offset];

        foreach ($builders as $position => $builder) {
            $queries[] = [
                ...$this->request($builder),
                ...($builder->weight === null ? [] : ['federationOptions' => ['weight' => $builder->weight]]),
            ];

            if (($facetFields[$position] = $this->facetFields($builder)) !== []) {
                $federation['facetsByIndex'][$builder->index->uid()] = array_values(array_map(
                    fn (IndexField $field): string => $this->path($builder, $field),
                    $facetFields[$position],
                ));
            }
        }

        $response = $this->transport->multiSearch($queries, $federation);
        $hits = [];
        $facets = [];

        foreach ($response['hits'] ?? [] as $hit) {
            if (($builder = $builders[$hit['_federation']['queriesPosition'] ?? -1] ?? null) !== null) {
                $hits[] = $this->hit($builder, $hit);
            }
        }

        foreach ($builders as $position => $builder) {
            $found = $response['facetsByIndex'][$builder->index->uid()] ?? null;

            if ($found !== null && $facetFields[$position] !== []) {
                $facets[$builder->index->entityType] = $this->facets($builder, $facetFields[$position], ['main' => [
                    'facetDistribution' => $found['distribution'] ?? [],
                    'facetStats' => $found['stats'] ?? [],
                ]]);
            }
        }

        return ['hits' => $hits, 'total' => (int) ($response['estimatedTotalHits'] ?? count($hits)), 'facets' => $facets];
    }

    /**
     * Search the values of one facet, within the builder's query and filters.
     *
     * @return list<array{value: string, label: string, count: int}>
     *
     * @throws InvalidSearchQueryException
     */
    public function facetValues(Builder $builder, string $name, ?string $query): array
    {
        $field = $this->filterable($builder, $name);
        $request = $this->request($builder, except: [$field->path]);

        $response = $this->transport->facetSearch($builder->index->uid(), array_filter([
            'facetName' => $this->path($builder, $field),
            'facetQuery' => $query,
            'q' => $request['q'] ?? null,
            'filter' => $request['filter'] ?? null,
            'attributesToSearchOn' => $request['attributesToSearchOn'] ?? null,
            'matchingStrategy' => $request['matchingStrategy'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        $counts = [];

        foreach ($response['facetHits'] ?? [] as $hit) {
            $counts[(string) $hit['value']] = (int) $hit['count'];
        }

        return $this->terms($builder, $field, $counts);
    }

    /**
     * The engine request of a builder, without paging.
     *
     * @param  list<string>  $except  Paths of fields whose top-level conditions are left out.
     * @param  bool|null  $side  Which side of the partition to take: its matches, or the rest; null for both.
     * @return array<string, mixed>
     */
    public function request(Builder $builder, array $except = [], ?bool $side = null): array
    {
        $index = $builder->index;
        $locales = $this->localesOf($builder);
        $request = ['indexUid' => $index->uid(), 'showRankingScore' => true];

        if ($builder->query !== null) {
            $request['q'] = $builder->query;

            if (($searchOn = $this->searchOn($builder, $locales)) !== []) {
                $request['attributesToSearchOn'] = $searchOn;
            }

            $languages = array_values(array_unique(array_filter(array_map($this->settings->language(...), $locales))));

            if ($languages !== [] && $index->paths(static fn (IndexField $field): bool => $field->localized && $field->searchable) !== []) {
                $request['locales'] = $languages;
            }
        }

        $filters = [];

        foreach ($builder->filters as $parsed) {
            $filters[] = $this->compiler->compile($parsed, $index, $locales[0] ?? null, $except);
        }

        if ($side !== null && $builder->partition !== null) {
            $condition = $this->compiler->compile($builder->partition, $index, $locales[0] ?? null);
            $filters[] = $condition === null || $side ? $condition : "NOT ({$condition})";
        }

        if (($filters = array_values(array_filter($filters))) !== []) {
            $request['filter'] = implode(' AND ', array_map(static fn (string $filter): string => "({$filter})", $filters));
        }

        foreach ($builder->orders as [$name, $direction]) {
            $field = $index->find($name) ?? throw InvalidSearchQueryException::unknownField($name);

            if (! $field->sortable) {
                throw InvalidSearchQueryException::notSortable($name);
            }

            $request['sort'][] = $this->path($builder, $field).':'.$direction;
        }

        if ($builder->distinct !== null) {
            $request['distinct'] = $this->filterable($builder, $builder->distinct)->path;
        }

        if ($builder->highlight !== null) {
            $request['attributesToHighlight'] = $builder->highlight === []
                ? ($request['attributesToSearchOn'] ?? ['*'])
                : array_map(fn (string $name): string => $this->path($builder, $this->field($builder, $name)), $builder->highlight);
        }

        return [...$request, ...$builder->options];
    }

    /**
     * Fill a partitioned page: the leading side first, topped up from the trailing side once it runs out.
     *
     * @param  array<string, mixed>  $leading
     * @return list<array<string, mixed>>
     */
    private function partitionHits(Builder $builder, array $leading, int $perPage, int $page): array
    {
        $hits = $leading['hits'];
        $missing = $perPage - count($hits);

        if ($missing <= 0) {
            return $hits;
        }

        $trailing = $this->transport->multiSearch([[
            ...$this->request($builder, side: ! $builder->partitionFirst),
            'limit' => $missing,
            'offset' => max(0, ($page - 1) * $perPage - (int) ($leading['totalHits'] ?? 0)),
        ]])['results'][0];

        return [...$hits, ...$trailing['hits']];
    }

    /**
     * Shape the engine's facet data by the names the facets were asked for.
     *
     * @param  array<string, IndexField>  $fields
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, mixed>
     */
    private function facets(Builder $builder, array $fields, array $responses): array
    {
        $facets = [];

        foreach ($fields as $name => $field) {
            $response = $responses["facet:{$name}"] ?? $responses['main'];
            $path = $this->path($builder, $field);

            if ($field->type->isNumeric()) {
                $stats = $response['facetStats'][$path] ?? null;

                if ($stats !== null) {
                    $facets[$name] = ['type' => 'range', 'min' => $stats['min'] ?? null, 'max' => $stats['max'] ?? null];
                }

                continue;
            }

            $facets[$name] = ['type' => 'terms', 'values' => $this->terms($builder, $field, $response['facetDistribution'][$path] ?? [])];
        }

        return $facets;
    }

    /**
     * Facet values with their display labels.
     *
     * @param  array<array-key, int>  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    private function terms(Builder $builder, IndexField $field, array $counts): array
    {
        $values = array_map(strval(...), array_keys($counts));
        $labels = [];

        if ($field->attribute !== null && $values !== []) {
            $locale = $this->localesOf($builder)[0] ?? null;

            $labels = $this->fields->make($field->attribute)->facetLabels(
                $values,
                ($locale !== null ? $this->locales->find($locale) : null) ?? $this->locales->current(),
            );
        }

        $terms = [];

        foreach ($counts as $value => $count) {
            $terms[] = ['value' => (string) $value, 'label' => (string) ($labels[(string) $value] ?? $value), 'count' => (int) $count];
        }

        return $terms;
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function hit(Builder $builder, array $hit): Hit
    {
        $formatted = $hit['_formatted'] ?? [];
        $score = $hit['_rankingScore'] ?? null;

        unset($hit['_formatted'], $hit['_rankingScore'], $hit['_federation'], $hit['_rankingScoreDetails'], $hit['_matchesPosition']);

        return new Hit(
            key: $hit['id'],
            entityType: $builder->index->entityType,
            document: $hit,
            formatted: $formatted,
            score: $score === null ? null : (float) $score,
            index: $builder->index,
            locales: $this->localesOf($builder),
        );
    }

    /**
     * Fields the facets were asked for, keyed by the asked name.
     *
     * @return array<string, IndexField>
     */
    private function facetFields(Builder $builder): array
    {
        $fields = [];

        foreach ($builder->facets as $name) {
            $fields[$name] = $this->filterable($builder, $name);
        }

        return $fields;
    }

    /**
     * Whether a top-level condition of the builder constrains the field.
     */
    private function constrained(Builder $builder, IndexField $field): bool
    {
        foreach ($builder->filters as $parsed) {
            if ($this->compiler->constrains($parsed, $builder->index, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paths the text is matched against: every searchable field, or the ones the builder narrows to.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function searchOn(Builder $builder, array $locales): array
    {
        if ($builder->searchOn === null) {
            return $builder->index->paths(static fn (IndexField $field): bool => $field->searchable, $locales);
        }

        $paths = [];

        foreach ($builder->searchOn as $name) {
            $field = $this->field($builder, $name);

            if (! $field->searchable) {
                throw InvalidSearchQueryException::rejected("field [{$name}] is not searchable.");
            }

            array_push($paths, ...$field->paths($locales));
        }

        return array_values(array_unique($paths));
    }

    private function field(Builder $builder, string $name): IndexField
    {
        return $builder->index->find($name) ?? throw InvalidSearchQueryException::unknownField($name);
    }

    private function filterable(Builder $builder, string $name): IndexField
    {
        $field = $this->field($builder, $name);

        return $field->filterable ? $field : throw InvalidSearchQueryException::notFilterable($name);
    }

    /**
     * The field's path in the builder's first locale.
     */
    private function path(Builder $builder, IndexField $field): string
    {
        return $field->pathFor($this->localesOf($builder)[0] ?? null);
    }

    /**
     * Locale codes the builder searches in: its own, or the current locale.
     *
     * @return list<string>
     */
    private function localesOf(Builder $builder): array
    {
        if ($builder->locales !== []) {
            return $builder->locales;
        }

        try {
            $code = $this->locales->code($this->locales->current());
        } catch (InvalidConfigurationException) {
            // No locales are set up: nothing is localized either.
            $code = null;
        }

        return $code === null ? [] : [$code];
    }
}
