<?php

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Support\Concerns\ExecutesPersistence;

/**
 * Persists EAV attribute values for a single entity.
 */
class AttributePersister
{
    use ExecutesPersistence;

    public function __construct(private readonly Attributable $entity)
    {
    }

    /** @param  Collection<int, Field>  $fields */
    public function persist(Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $this->withinTimestamp(fn () => $this->persistGroup(
            $this->entity->attributeEntityType(),
            [$this->entity->id => $fields],
        ));
    }

    public function save(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /** @param  Collection<int, Field>  $fields */
    public function replace(Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $this->withinTimestamp(fn () => DB::transaction(function () use ($fields): void {
            $keepIds = $fields->map(fn (Field $f) => $f->attribute()->id)->values()->all();
            $this->delete($this->entityQuery()->whereNotIn('attribute_id', $keepIds)->pluck('id')->all());
            $this->persistGroup($this->entity->attributeEntityType(), [$this->entity->id => $fields]);
        }));
    }

    /** @param  array<int>  $attributeIds */
    public function deleteExcluding(array $attributeIds): void
    {
        $this->delete(
            $this->entityQuery()->whereNotIn('attribute_id', $attributeIds)->pluck('id')->all(),
        );
    }

    /** @param  array<int>  $attributeIds */
    public function detach(array $attributeIds): void
    {
        $this->delete(
            $this->entityQuery()->whereIn('attribute_id', $attributeIds)->pluck('id')->all(),
        );
    }

    private function entityQuery(): Builder
    {
        return EavModels::query(self::MODEL_ATTRIBUTE)
            ->where('entity_type', $this->entity->attributeEntityType())
            ->where('entity_id', $this->entity->id);
    }
}
