<?php

namespace Jurager\Eav\Registry;

use Jurager\Eav\Support\EavModels;

/**
 * In-memory cache for enums data.
 */
class EnumRegistry
{
    /** @var array<int, array<int, true>> */
    private array $enums = [];

    /**
     * Returns a lookup map of valid enum IDs for the given attribute.
     *
     * @return array<int, true>
     */
    public function resolve(int $attributeId): array
    {
        if (! isset($this->enums[$attributeId])) {
            $ids = EavModels::query('attribute_enum')
                ->where('attribute_id', $attributeId)
                ->pluck('id')
                ->all();

            $this->enums[$attributeId] = array_fill_keys($ids, true);
        }

        return $this->enums[$attributeId];
    }

    /**
     * Forget cached enum IDs for a specific attribute, or all attributes when null.
     */
    public function forget(?int $attributeId = null): void
    {
        if ($attributeId === null) {
            $this->enums = [];
        } else {
            unset($this->enums[$attributeId]);
        }
    }
}
