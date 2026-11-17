<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Engines\Meilisearch;

use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Turn a search builder into a Meilisearch search request.
 */
class RequestFactory
{
    public function __construct(
        private readonly FilterCompiler $compiler,
        private readonly Settings $settings,
    ) {}

    /**
     * The engine request of a builder, without paging and facets.
     *
     * @param  list<string>  $except  Paths of fields whose top-level conditions are left out.
     * @param  bool|null  $side  Which side of the partition to take: its matches, or the rest; null for both.
     * @param  Group|null  $boosted  A boosted condition to narrow the search to.
     * @return array<string, mixed>
     *
     * @throws InvalidSearchQueryException
     */
    public function make(Builder $builder, array $except = [], ?bool $side = null, ?Group $boosted = null): array
    {
        $request = [
            'indexUid' => $builder->index->uid(),
            'showRankingScore' => true,
            ...$this->text($builder),
        ];

        if (($filter = $this->filter($builder, $except, $side, $boosted)) !== null) {
            $request['filter'] = $filter;
        }

        foreach ($builder->orders as [$field, $direction]) {
            $request['sort'][] = $builder->path($field).':'.$direction;
        }

        if ($builder->distinct !== null) {
            $request['distinct'] = $builder->filterable($builder->distinct)->path;
        }

        if ($builder->highlight !== null) {
            $request['attributesToHighlight'] = $builder->highlight === []
                ? ($request['attributesToSearchOn'] ?? ['*'])
                : array_map(static fn (string $name): string => $builder->path($builder->field($name)), $builder->highlight);
        }

        if ($builder->matching !== null) {
            $request['matchingStrategy'] = $builder->matching;
        }

        if ($builder->threshold !== null) {
            $request['rankingScoreThreshold'] = $builder->threshold;
        }

        return [...$request, ...$builder->options];
    }

    /**
     * The text to match, the fields to match it against, and the languages it may be written in.
     *
     * @return array<string, mixed>
     */
    private function text(Builder $builder): array
    {
        if ($builder->query === null) {
            return [];
        }

        $request = ['q' => $builder->query];

        if (($paths = $this->searchOn($builder)) !== []) {
            $request['attributesToSearchOn'] = $paths;
        }

        $languages = array_values(array_unique(array_filter(array_map($this->settings->language(...), $builder->locales()))));
        $localized = $builder->index->paths(static fn (IndexField $field): bool => $field->localized && $field->searchable);

        if ($languages !== [] && $localized !== []) {
            $request['locales'] = $languages;
        }

        return $request;
    }

    /**
     * Paths the text is matched against: every searchable field, or the ones the builder narrows to.
     *
     * @return list<string>
     */
    private function searchOn(Builder $builder): array
    {
        if ($builder->searchOn === null) {
            return $builder->index->paths(static fn (IndexField $field): bool => $field->searchable, $builder->locales());
        }

        $paths = [];

        foreach ($builder->searchOn as $name) {
            array_push($paths, ...$builder->field($name)->paths($builder->locales()));
        }

        return array_values(array_unique($paths));
    }

    /**
     * Every condition of the builder as one expression, each kept apart in parentheses.
     *
     * @param  list<string>  $except
     */
    private function filter(Builder $builder, array $except, ?bool $side, ?Group $boosted): ?string
    {
        $locale = $builder->locales()[0] ?? null;
        $filters = [];

        foreach ($builder->filters as $group) {
            $filters[] = $this->compiler->compile($group->except($except), $locale);
        }

        if ($side !== null && $builder->partition !== null) {
            $condition = $this->compiler->compile($builder->partition, $locale);
            $filters[] = $condition === null || $side ? $condition : "NOT ({$condition})";
        }

        if ($boosted !== null) {
            $filters[] = $this->compiler->compile($boosted, $locale);
        }

        if ($builder->index->softDeletes && $builder->trashed !== true) {
            $filters[] = Index::SOFT_DELETED.' = '.($builder->trashed === null ? 1 : 0);
        }

        $filters = array_filter($filters);

        return $filters === [] ? null : implode(' AND ', array_map(static fn (string $filter): string => "({$filter})", $filters));
    }
}
