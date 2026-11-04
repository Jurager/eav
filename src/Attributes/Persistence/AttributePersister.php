<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Fields\Field;

/**
 * Persists the attribute values of a single entity.
 */
class AttributePersister
{
    public function __construct(
        private readonly Attributable $entity,
        private readonly ValueWriter $writer,
    ) {}

    /** @param  Collection<array-key, Field>  $fields */
    public function persist(Collection $fields): void
    {
        if ($fields->isNotEmpty()) {
            $this->writer->write($this->entity->getEntityType(), [$this->entity->getKey() => $fields]);
        }
    }

    public function save(Field $field): void
    {
        $this->persist(collect([$field]));
    }

    /**
     * Replace all stored attribute values with the given fields.
     *
     * @param  Collection<array-key, Field>  $fields
     */
    public function replace(Collection $fields): void
    {
        $this->writer->transaction(function () use ($fields): void {
            $kept = $fields->map(static fn (Field $field) => $field->attribute()->getKey())->all();

            $this->drop($this->rows()->whereNotIn('attribute_id', $kept)->pluck('id')->all());
            $this->persist($fields);
        });
    }

    /**
     * Delete the stored values of the given attributes.
     *
     * @param  list<int>  $attributeIds
     */
    public function detach(array $attributeIds): void
    {
        $this->drop($this->rows()->whereIn('attribute_id', $attributeIds)->pluck('id')->all());
    }

    /** @param  list<int>  $rowIds */
    private function drop(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $this->writer->delete($rowIds);

        // Losing a value changes the search document just as writing one does.
        EntityValuesChanged::dispatch($this->entity->getEntityType(), [$this->entity->getKey()]);
    }

    /**
     * The entity's own rows.
     */
    private function rows(): Builder
    {
        return Eav::$entityAttributeModel::query()
            ->where('entity_type', $this->entity->getEntityType())
            ->where('entity_id', $this->entity->getKey());
    }
}
