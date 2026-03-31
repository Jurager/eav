<?php

namespace Jurager\Eav\Registry;

use Jurager\Eav\Support\EavModels;

/**
 * Process-level cache of valid enum IDs keyed by attribute_id.
 */
class EnumRegistry
{
    /** @var array<int, array<int, true>> */
    private array $cache = [];

    /**
     * Return valid enum IDs as a flip-array (id => true) for O(1) lookup,
     * loading from the database on first access per attribute.
     *
     * @return array<int, true>
     */
    public function resolve(int $attributeId): array
    {
        if (! isset($this->cache[$attributeId])) {
            $ids = EavModels::query('attribute_enum')
                ->where('attribute_id', $attributeId)
                ->pluck('id')
                ->all();

            $this->cache[$attributeId] = array_fill_keys($ids, true);
        }

        return $this->cache[$attributeId];
    }

    /**
     * Forget cached enum IDs for a specific attribute, or all attributes when null.
     */
    public function forget(?int $attributeId = null): void
    {
        if ($attributeId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$attributeId]);
        }
    }
}
