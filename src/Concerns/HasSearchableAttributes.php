<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Scopes\ActiveLocaleScope;
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
     * Whether the save in progress writes attribute values, which reindexes the entity by itself.
     */
    private bool $savesAttributeValues = false;

    public static function bootHasSearchableAttributes(): void
    {
        static::saving(static function (self $model): void {
            // Only a value being written is sure to raise the change that reindexes; clearing one may find nothing to drop.
            $model->savesAttributeValues = array_any(
                $model->attributeManager?->getDirty() ?? [],
                static fn (Field $field): bool => $field->isFilled(),
            );
        });
    }

    /**
     * Declare the model's own index fields; indexed attributes are added on top.
     */
    public function searchIndex(Index $index): void {}

    public function toSearchableArray(): array
    {
        return app(Document::class)->build($this);
    }

    /**
     * Skip reindexing a save that cannot have changed the document, or that is reindexed anyway.
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        // The values written with this save queue the reindex once they are committed: not twice.
        if ($this->savesAttributeValues) {
            return false;
        }

        if ($this->wasRecentlyCreated) {
            return true;
        }

        $columns = app(IndexFactory::class)->for($this)->watched();

        return $columns !== [] && $this->wasChanged($columns);
    }

    /**
     * Load everything the index document is built from, for the whole batch at once.
     *
     * Loaded anew: relations already on the models may hold the active locale's translations only.
     *
     * @param  Collection<int, static>  $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        $models->load($this->indexRelations());

        app(Document::class)->prepared($models);

        return $models;
    }

    /**
     * Attribute values in every locale, plus the relations declared fields are read from.
     *
     * @return array<int|string, mixed>
     */
    private function indexRelations(): array
    {
        // The index holds every locale, whichever one the request runs in.
        $everyLocale = static fn ($query) => $query->withoutGlobalScope(ActiveLocaleScope::class);

        $relations = [
            'attribute_values.attribute.enums.translations' => $everyLocale,
            'attribute_values.translations' => $everyLocale,
            ...app(IndexFactory::class)->for($this)->relations(),
        ];

        if (($parent = $this->attributeParentRelationName()) !== null) {
            $relations["{$parent}.attribute_values.attribute.enums.translations"] = $everyLocale;
            $relations["{$parent}.attribute_values.translations"] = $everyLocale;

            if (($scope = $this->attributeScopeRelationName()) !== null) {
                $relations[] = "{$parent}.{$scope}";
            }
        }

        return $relations;
    }
}
