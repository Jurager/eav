<?php

namespace Jurager\Eav\Concerns;

/**
 * Integrates EAV attribute values with Laravel Scout search indexing.
 *
 * Use alongside the Searchable trait from Laravel Scout:
 *
 *   use Searchable, HasSearchableAttributes {
 *       HasSearchableAttributes::toSearchableArray insteadof Searchable;
 *       HasSearchableAttributes::shouldBeSearchable insteadof Searchable;
 *   }
 *
 * Override toSearchableArray() in your model to include model-specific fields
 * alongside the EAV attribute index data.
 */
trait HasSearchableAttributes
{
    /**
     * Build the search index payload for this entity.
     * EAV attributes with searchable: true are included automatically.
     *
     * Override to add model-specific fields:
     *
     *   public function toSearchableArray(): array
     *   {
     *       return ['id' => (string) $this->getScoutKey(), 'code' => $this->code, ...$this->eav()->indexData()];
     *   }
     */
    public function toSearchableArray(): array
    {
        return ['id' => (string) $this->getScoutKey(), ...$this->eav()->indexData()];
    }

    /**
     * Determine if this entity should be indexed.
     * Returns true when at least one searchable attribute has a stored value.
     */
    public function shouldBeSearchable(): bool
    {
        return ! empty($this->eav()->indexData());
    }
}
