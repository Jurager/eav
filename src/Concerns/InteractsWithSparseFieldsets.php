<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Closure;
use Jurager\Eav\Registry\AttributeRegistry;

/**
 * Load EAV values requested by JSON:API sparse fieldsets.
 *
 * @phpstan-require-implements \Jurager\Eav\Contracts\Attributable
 */
trait InteractsWithSparseFieldsets
{
    /**
     * Load local and inherited values for the requested attribute codes.
     *
     * @param  list<string>  $included
     * @param  list<string>|null  $fields
     * @return array<int|string, string|Closure>
     */
    public function eagerLoads(array $included, ?array $fields = null): array
    {
        $relations = [
            'attribute_values.attribute.type',
            'attribute_values.translations',
            ...$this->inheritedValueRelations(),
        ];

        if ($fields === null) {
            return $relations;
        }

        $relations['attribute_values'] = $this->attributeValuesSparseConstraint($fields);

        if (($parent = $this->attributeParentRelationName()) !== null) {
            $relations["{$parent}.attribute_values"] = $relations['attribute_values'];
        }

        return $relations;
    }

    /**
     * Map requested attribute codes to their value relation.
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    public static function fieldRelations(array $fields): array
    {
        $codes = app(AttributeRegistry::class)
            ->forCodes((new static)->getEntityType(), $fields)
            ->pluck('code')
            ->values()
            ->all();

        return $codes === [] ? [] : ['attribute_values' => $codes];
    }

    /**
     * Build a relation constraint selecting only attribute IDs present in the fieldset.
     */
    protected function attributeValuesSparseConstraint(array $fields): Closure
    {
        $ids = app(AttributeRegistry::class)
            ->forCodes($this->getEntityType(), $fields)
            ->pluck('id')
            ->all();

        return fn ($query) => $query->whereIn('attribute_id', $ids);
    }
}
