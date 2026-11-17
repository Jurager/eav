<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Dialects;

use Closure;
use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\Filters\Condition;
use Jurager\Eav\Search\Filters\Group;
use Jurager\Eav\Search\Hit;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexField;
use Jurager\Eav\Search\Response;
use Laravel\Scout\Exceptions\NotSupportedException;

/**
 * What a Scout engine is asked beyond Scout's own query API, in that engine's terms.
 *
 * This base speaks plain Scout: conditions become Scout's wheres and nothing else is known. A dialect of a
 * particular engine — see MeilisearchDialect — adds facets, per-locale search, federation and the rest.
 */
class Dialect
{
    /**
     * Whether the engine can do what Scout's query API cannot: facets, groups of conditions, searching
     * chosen fields or locales, highlighting, boosting, partitioning.
     */
    public function extended(): bool
    {
        return false;
    }

    /**
     * Take the conditions added to a search.
     *
     * @throws NotSupportedException When Scout's query API cannot express them.
     */
    public function where(Builder $search, Group $group): void
    {
        if ($group->any && count($group->members) > 1) {
            throw new NotSupportedException('The search engine in use cannot match any of several conditions.');
        }

        foreach ($group->members as $member) {
            $member instanceof Group ? $this->where($search, $member) : $this->native($search, $member);
        }
    }

    /**
     * Take a sort added to a search.
     */
    public function orderBy(Builder $search, IndexField $field, string $direction): void
    {
        $search->orders[] = ['column' => $search->path($field), 'direction' => $direction];
    }

    /**
     * The callback Scout's engine runs the search with, or null to let the engine search by itself.
     *
     * @return (Closure(mixed, string|null, array<string, mixed>): mixed)|null
     */
    public function callback(Builder $search): ?Closure
    {
        return null;
    }

    /**
     * Hits of the engine's raw results. Without a dialect the raw results are not understood.
     *
     * @return list<Hit>
     */
    public function hits(Builder $search, mixed $results): array
    {
        return [];
    }

    /**
     * Facet data of the engine's raw results, keyed by the asked names.
     *
     * @return array<string, mixed>
     */
    public function facets(Builder $search, mixed $results): array
    {
        return [];
    }

    /**
     * Values of a facet matching the text, with their counts.
     *
     * @param  string  $name  The name the facet was asked by.
     * @return array<string, int>
     */
    public function facetValues(Builder $search, string $name, IndexField $field, ?string $query): array
    {
        throw new NotSupportedException('The search engine in use cannot search facet values.');
    }

    /**
     * Run several searches as one list. Without native federation each search runs alone, and the hits
     * are ranked by weighted score — by position where the engine tells no score.
     *
     * @param  list<Builder>  $searches
     */
    public function federated(array $searches, int $limit, int $offset): Response
    {
        $ranked = [];
        $facets = [];
        $total = 0;

        foreach ($searches as $position => $search) {
            // Any of the searches may fill the whole page, so each is asked for all of it.
            $results = $search->paginate($offset + $limit, 'page', 1);
            $total += $results->total();

            foreach ($results->hits() as $rank => $hit) {
                $ranked[] = [($hit->score ?? 1 / ($rank + 1)) * ($search->weight ?? 1.0), $position, $rank, $hit];
            }

            if ($results->facets() !== []) {
                $facets[$search->definition->entityType] = $results->facets();
            }
        }

        // Best weighted score first; equal scores keep the order of the searches and of their own hits.
        usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        return new Response(array_column(array_slice($ranked, $offset, $limit), 3), $total, $facets, formatted: true);
    }

    /**
     * Engine settings derived from an index definition, or null when the engine needs none.
     *
     * @return array<string, mixed>|null
     */
    public function settings(Index $index): ?array
    {
        return null;
    }

    /**
     * Express one condition with Scout's own wheres.
     */
    private function native(Builder $search, Condition $condition): void
    {
        $path = $search->path($condition->field);
        $where = static fn (string $operator, mixed $value): array => ['field' => $path, 'operator' => $operator, 'value' => $value];

        match ($condition->comparison) {
            Comparison::Equal => $search->wheres[] = $where('=', $condition->value),
            Comparison::NotEqual => $search->wheres[] = $where('!=', $condition->value),
            Comparison::Greater => $search->wheres[] = $where('>', $condition->value),
            Comparison::GreaterOrEqual => $search->wheres[] = $where('>=', $condition->value),
            Comparison::Less => $search->wheres[] = $where('<', $condition->value),
            Comparison::LessOrEqual => $search->wheres[] = $where('<=', $condition->value),
            Comparison::In => $search->whereIns[$path] = $condition->value,
            Comparison::NotIn => $search->whereNotIns[$path] = $condition->value,
            Comparison::Between => array_push($search->wheres, $where('>=', $condition->value[0]), $where('<=', $condition->value[1])),
            Comparison::Missing => $search->wheres[] = $where('=', null),
            Comparison::Exists => $search->wheres[] = $where('!=', null),
            default => throw new NotSupportedException("The search engine in use cannot compare with [{$condition->comparison->value}]."),
        };
    }
}
