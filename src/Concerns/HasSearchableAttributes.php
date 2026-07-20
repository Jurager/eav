<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Laravel\Scout\Searchable;

/**
 * Add Scout search support with attribute indexing.
 *
 * @mixin Searchable
 */
trait HasSearchableAttributes
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['id' => (string) $this->getScoutKey(), ...$this->eav()->indexData()];
    }

    public function shouldBeSearchable(): bool
    {
        return ! empty($this->eav()->indexData());
    }
}
