<?php

namespace Jurager\Eav\Support;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Support\Concerns\ExecutesPersistence;

/**
 * Persists EAV attribute values for multiple entities in chunked batches.
 *
 * Accumulate entities via add(), then flush() to write them all in a single
 * pass per entity type. Reuse across multiple chunks for bulk imports.
 */
class BatchAttributePersister
{
    use ExecutesPersistence;

    /** @var array<string, array<int|string, Collection<int, Field>>> */
    private array $pending = [];

    /** @var array<int|string, Attributable> */
    private array $entities = [];

    /** @param  Collection<int, Field>  $fields */
    public function add(Attributable $entity, Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $type = $entity->attributeEntityType();
        $entityId = $entity->getKey();

        $this->pending[$type][$entityId] = ($this->pending[$type][$entityId] ?? collect())
            ->merge($fields)
            ->unique(fn (Field $f) => $f->attribute()->id)
            ->values();

        $this->entities[$entityId] = $entity;
    }

    /**
     * Write all pending entities to the database.
     *
     * If $onError is provided, a failing entity group's exception is passed to the
     * callback and processing continues; without it the exception is re-thrown.
     *
     * @param  callable(\Throwable, Attributable): void|null  $onError
     */
    public function flush(?callable $onError = null): void
    {
        $this->withinTimestamp(function () use ($onError): void {
            foreach ($this->pending as $type => $grouped) {
                try {
                    $this->persistGroup($type, $grouped);
                } catch (\Throwable $e) {
                    if ($onError !== null) {
                        foreach (array_keys($grouped) as $entityId) {
                            $onError($e, $this->entities[$entityId]);
                        }
                    } else {
                        throw $e;
                    }
                }
            }
        });

        $this->pending = [];
        $this->entities = [];
    }
}
