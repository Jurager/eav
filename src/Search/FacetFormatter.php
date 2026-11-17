<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Shape the engine's facet data: by the names the facets were asked for, with display labels.
 */
class FacetFormatter
{
    public function __construct(
        private readonly FieldFactory $fields,
        private readonly LocaleRegistry $locales,
    ) {}

    /**
     * Facets of a response: a range for a numeric or date field, values with counts for any other.
     *
     * @param  array<string, IndexField>  $fields  Facet fields keyed by the asked name.
     * @param  array<string, mixed>  $response  The response holding the facets.
     * @param  array<string, array<string, mixed>>  $scoped  Responses computed for one facet alone, keyed by its name.
     * @return array<string, mixed>
     */
    public function format(Builder $builder, array $fields, array $response, array $scoped = []): array
    {
        $facets = [];

        foreach ($fields as $name => $field) {
            $source = $scoped[$name] ?? $response;
            $path = $builder->path($field);

            if (! $field->type->isNumeric()) {
                $facets[$name] = ['type' => 'terms', 'values' => $this->terms($builder, $field, $source['facetDistribution'][$path] ?? [])];
            } elseif (($stats = $source['facetStats'][$path] ?? null) !== null) {
                $facets[$name] = ['type' => 'range', 'min' => $stats['min'] ?? null, 'max' => $stats['max'] ?? null];
            }
        }

        return $facets;
    }

    /**
     * Facet values with their counts and display labels.
     *
     * @param  array<array-key, int>  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    public function terms(Builder $builder, IndexField $field, array $counts): array
    {
        $labels = $field->attribute === null || $counts === [] ? [] : $this->fields->make($field->attribute)->facetLabels(
            array_map(strval(...), array_keys($counts)),
            $this->locales->find($builder->locales()[0] ?? '') ?? $this->locales->current(),
        );

        $terms = [];

        foreach ($counts as $value => $count) {
            $terms[] = ['value' => (string) $value, 'label' => (string) ($labels[(string) $value] ?? $value), 'count' => (int) $count];
        }

        return $terms;
    }
}
