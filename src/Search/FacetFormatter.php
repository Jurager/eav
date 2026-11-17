<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Search\Index\IndexField;

/**
 * Shape the facet data an engine returned: one form per kind of field, with display labels.
 */
class FacetFormatter
{
    public function __construct(
        private readonly FieldFactory $fields,
        private readonly LocaleRegistry $locales,
    ) {}

    /**
     * Facets of a search: a range for a numeric or date field, values with counts for any other.
     *
     * @param  array<string, mixed>  $facets  Facet data keyed by the asked name, as the engine returned it.
     * @return array<string, mixed>
     */
    public function format(Builder $search, array $facets): array
    {
        $formatted = [];

        foreach ($search->facets as $name) {
            $field = $search->filterable($name);
            $facet = $facets[$name] ?? [];

            if (! $field->type->isNumeric()) {
                $formatted[$name] = ['type' => 'terms', 'values' => $this->terms($search, $field, $facet)];
            } elseif (isset($facet['min'], $facet['max'])) {
                $formatted[$name] = ['type' => 'range', 'min' => $facet['min'], 'max' => $facet['max']];
            }
        }

        return $formatted;
    }

    /**
     * Facet values with their counts and display labels.
     *
     * @param  array<array-key, int>  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    public function terms(Builder $search, IndexField $field, array $counts): array
    {
        $labels = $field->attribute === null || $counts === [] ? [] : $this->fields->make($field->attribute)->facetLabels(
            array_map(strval(...), array_keys($counts)),
            $this->locales->find($search->locales()[0] ?? '') ?? $this->locales->current(),
        );

        $terms = [];

        foreach ($counts as $value => $count) {
            $terms[] = ['value' => (string) $value, 'label' => (string) ($labels[(string) $value] ?? $value), 'count' => (int) $count];
        }

        return $terms;
    }
}
