<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Relations this entity needs beyond its attribute values, on top of what EAV loads itself.
     *
     * @return list<string>
     */
    protected function searchableWith(): array
    {
        return [];
    }

    /**
     * Eager load everything the index document is built from, for the whole batch at once.
     *
     * @param Collection<int, static> $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->loadMissing($this->indexRelations());
    }

    /**
     * Eager load the same relations when importing the whole model.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with($this->indexRelations());
    }

    /**
     * Attribute value relations are always included — a model declaring its own cannot drop them.
     *
     * @return list<string>
     */
    private function indexRelations(): array
    {
        return [
            'attribute_values.attribute.enums.translations',
            'attribute_values.translations',
            ...$this->searchableWith(),
        ];
    }
}
