<?php

declare(strict_types=1);

namespace Jurager\Eav\Media;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Media\Contracts\MediaCleaner;
use Jurager\Media\Models\Media;

/** Deletes media no longer referenced by a MediaField attribute value — register in config('media.cleaners'). */
class OrphanedMediaCleaner implements MediaCleaner
{
    public function __construct(protected FieldFactory $fields)
    {
    }

    public function orphaned(Collection $candidates, string $entityType, string $modelClass): Collection
    {
        if (! is_a($modelClass, Attributable::class, true)) {
            return collect();
        }

        $mediaAttributes = $this->fields->using(MediaField::class, $entityType);

        if ($mediaAttributes->isEmpty()) {
            return collect();
        }

        $allowedCodes = $mediaAttributes->pluck('code')->all();

        $relevant = $candidates->filter(
            fn (Media $media) => in_array($media->getAttribute('collection_name'), $allowedCodes, true)
        );

        return $relevant->isNotEmpty()
            ? $this->rejectReferenced($relevant, $entityType, $mediaAttributes->keys()->all())
            : collect();
    }

    protected function rejectReferenced(Collection $candidates, string $entityType, array $attributeIds): Collection
    {
        $referencedIds = $this->referencedValueQuery($candidates, $entityType, $attributeIds)
            ->pluck('value_integer')
            ->flip();

        return $candidates->reject(
            fn (Media $media) => $referencedIds->has($media->getAttribute('id'))
        );
    }

    protected function referencedValueQuery(Collection $candidates, string $entityType, array $attributeIds): Builder
    {
        return Eav::$entityAttributeModel::query()
            ->where('entity_type', $entityType)
            ->whereIn('attribute_id', $attributeIds)
            ->where(fn (Builder $query) => $this->constrainToCandidateOwners($query, $candidates));
    }

    protected function constrainToCandidateOwners(Builder $query, Collection $candidates): void
    {
        $candidates->groupBy('mediable_id')->each(
            fn (Collection $group, int|string $entityId) => $query->orWhere(
                fn (Builder $owner) => $owner
                    ->where('entity_id', $entityId)
                    ->whereIn('value_integer', $group->pluck('id'))
            )
        );
    }
}
