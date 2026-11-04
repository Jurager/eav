<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Jurager\Eav\Attributes\EntityValues;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\FieldFactory;

/**
 * Build search data from searchable and filterable attributes.
 */
class IndexDocument
{
    public function __construct(private readonly FieldFactory $fields) {}

    /** @return array{attributes?: array<string, mixed>} */
    public function build(Attributable $entity, EntityValues $values): array
    {
        $attributes = [];

        foreach ($values->indexable()->groupBy('attribute_id') as $rows) {
            $field = $this->fields->make($rows->first()->attribute)->forEntity($entity);
            $field->hydrate($rows);

            $attributes += $field->indexData();
        }

        return $attributes === [] ? [] : ['attributes' => $attributes];
    }
}
