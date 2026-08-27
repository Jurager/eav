<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Events\EntityValuesChanged;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Support\Concerns\ExecutesPersistence;

/**
 * Persists EAV attribute values for a single entity.
 */
class AttributePersister
{
    use ExecutesPersistence;

    public function __construct(
        private readonly Attributable $entity,
        private readonly ConnectionResolverInterface $db,
    ) {
    }

    /** @param  Collection<int, Field>  $fields */
    public function persist(Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $this->persistGroup($this->entity->getEntityType(), [$this->entity->id => $fields]);
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

        $this->db->connection()->transaction(function () use ($fields): void {
            $keepIds = $fields->map->attribute()->pluck('id');
            $this->delete($this->entityQuery()->whereNotIn('attribute_id', $keepIds)->pluck('id')->all());
            $this->persistGroup($this->entity->getEntityType(), [$this->entity->id => $fields]);
        });
    }

    /** @param  array<int>  $attributeIds */
    public function deleteExcluding(array $attributeIds): void
    {
        $this->dropValues($this->entityQuery()->whereNotIn('attribute_id', $attributeIds)->pluck('id')->all());
    }

    /** @param  array<int>  $attributeIds */
    public function detach(array $attributeIds): void
    {
        $this->dropValues($this->entityQuery()->whereIn('attribute_id', $attributeIds)->pluck('id')->all());
    }

    /**
     * Drop the given values and reindex — losing a value changes the document just as writing one does.
     *
     * @param array<int> $ids
     */
    private function dropValues(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->delete($ids);

        EntityValuesChanged::dispatch($this->entity->getEntityType(), [$this->entity->id]);
    }

    private function entityQuery(): Builder
    {
        return Eav::$entityAttributeModel::query()
            ->where('entity_type', $this->entity->getEntityType())
            ->where('entity_id', $this->entity->id);
    }
}
