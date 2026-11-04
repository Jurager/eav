<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Persistence;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Throwable;

/**
 * Persist attribute values for multiple entities in bulk.
 */
class BatchAttributePersister
{
    /** @var array<string, array<int|string, array<int|string, Field>>> Fields by entity type, entity key and attribute id. */
    private array $pending = [];

    /** @var array<string, array<int|string, Attributable>> Entities by type, then key. */
    private array $entities = [];

    public function __construct(private readonly ValueWriter $writer) {}

    /**
     * Queue fields of an entity; a field added again for the same attribute replaces the earlier one.
     *
     * @param  Collection<array-key, Field>  $fields
     */
    public function add(Attributable $entity, Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $type = $entity->getEntityType();
        $entityId = $entity->getKey();

        foreach ($fields as $field) {
            $this->pending[$type][$entityId][$field->attribute()->getKey()] = $field;
        }

        $this->entities[$type][$entityId] = $entity;
    }

    /**
     * Write pending entities, retrying failures individually when $onError is given.
     *
     * @param  callable(Throwable, Attributable): void|null  $onError
     */
    public function flush(?callable $onError = null): void
    {
        foreach ($this->pending as $type => $grouped) {
            if ($onError === null) {
                $this->writer->write($type, $grouped);

                continue;
            }

            try {
                $this->writer->write($type, $grouped);
            } catch (Throwable) {
                foreach ($grouped as $entityId => $fields) {
                    try {
                        $this->writer->write($type, [$entityId => $fields]);
                    } catch (Throwable $e) {
                        $onError($e, $this->entities[$type][$entityId]);
                    }
                }
            }
        }

        $this->pending = [];
        $this->entities = [];
    }
}
