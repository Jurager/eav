<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes;

use Illuminate\Support\Collection;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\EntityAttribute;

class AttributeValueSorter
{
    public function __construct(private readonly FieldFactory $fields) {}

    /**
     * Order definitions by schema, then their values by the field's own ordering.
     */
    public function sort(Collection $values): Collection
    {
        $definitions = [];
        $rows = [];

        /** @var EntityAttribute $value */
        foreach ($values as $value) {
            [$group, $sort, $id, $field] = $definitions[$value->attribute_id] ??= $this->definition($value->attribute);

            $rows[] = [$group, $sort, $id, $field->sortOrder($value), count($rows), $value];
        }

        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2], $a[3], $a[4]] <=> [$b[0], $b[1], $b[2], $b[3], $b[4]]);

        return $values::make(array_column($rows, 5));
    }

    /**
     * The schema key a definition sorts by, and the field that orders its values.
     *
     * @return array{0: int, 1: int, 2: int, 3: Field}
     */
    private function definition(Attribute $attribute): array
    {
        return [...$attribute->schemaPosition(), $this->fields->make($attribute)];
    }
}
