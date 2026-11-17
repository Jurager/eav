<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Jurager\Eav\Search\Index\Document;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexFactory;
use Laravel\Scout\Searchable;

/**
 * Index the entity with Scout: the fields it declares in `searchIndex()` and its indexed attributes.
 *
 * @mixin Searchable
 */
trait HasSearchableAttributes
{
    use Searchable;

    /**
     * Declare the model's own index fields; indexed attributes are added on top.
     */
    public function searchIndex(Index $index): void {}

    public function toSearchableArray(): array
    {
        return app(Document::class)->build($this);
    }

    /**
     * Skip reindexing a save that cannot have changed the document.
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        if ($this->wasRecentlyCreated) {
            return true;
        }

        $columns = app(IndexFactory::class)->for($this)->watched();

        return $columns !== [] && $this->wasChanged($columns);
    }

    /**
     * Eager load everything the index document is built from, for the whole batch at once.
     *
     * @param  Collection<int, static>  $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->loadMissing($this->indexRelations());
    }

    /**
     * Eager load the same relations when importing the whole model.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with($this->indexRelations());
    }

    /**
     * Attribute value relations, plus the relations declared fields are read from.
     *
     * @return list<string>
     */
    private function indexRelations(): array
    {
        $relations = [
            'attribute_values.attribute.enums.translations',
            'attribute_values.translations',
            ...app(IndexFactory::class)->for($this)->relations(),
        ];

        if (($parent = $this->attributeParentRelationName()) !== null) {
            $relations[] = "{$parent}.attribute_values.attribute.enums.translations";
            $relations[] = "{$parent}.attribute_values.translations";

            if (($scope = $this->attributeScopeRelationName()) !== null) {
                $relations[] = "{$parent}.{$scope}";
            }
        }

        return $relations;
    }
}
