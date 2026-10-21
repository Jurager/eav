<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Illuminate\Support\Collection;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Models\EntityAttribute;

class AttributeValueSorter
{
    public function __construct(private readonly FieldFactory $fields) {}

    /** Order definitions by schema, then their values by the field's own ordering. */
    public function sort(Collection $values): Collection
    {
        $groups = $values->groupBy(static fn (EntityAttribute $value) => $value->attribute_id)
            ->sortBy(static function (Collection $group): array {
                $attribute = $group->first()->attribute;

                return [$attribute->attribute_group_id ?? 0, $attribute->sort ?? 0, $attribute->id];
            });

        $sorted = $groups->flatMap(function (Collection $group): Collection {
            $field = $this->fields->make($group->first()->attribute);

            return $group->sortBy(fn (EntityAttribute $value) => $field->sortOrder($value));
        });

        return $values::make($sorted)->values();
    }
}
