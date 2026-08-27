<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Support\Concerns\ExecutesPersistence;

class BatchAttributePersister
{
    use ExecutesPersistence;

    /** @var array<string, array<int|string, Collection<int, Field>>> */
    private array $pending = [];

    /** @var array<int|string, Attributable> */
    private array $entities = [];

    public function __construct(private readonly ConnectionResolverInterface $db)
    {
    }

    /** @param  Collection<int, Field>  $fields */
    public function add(Attributable $entity, Collection $fields): void
    {
        if ($fields->isEmpty()) {
            return;
        }

        $type = $entity->getEntityType();
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
     * @param  callable(\Throwable, Attributable): void|null  $onError
     */
    public function flush(?callable $onError = null): void
    {
        foreach ($this->pending as $type => $grouped) {
            if ($onError === null) {
                $this->persistGroup($type, $grouped);
            } else {
                try {
                    $this->db->connection()->transaction(fn () => $this->persistGroup($type, $grouped));
                } catch (\Throwable) {
                    foreach ($grouped as $entityId => $fields) {
                        try {
                            $this->db->connection()->transaction(fn () => $this->persistGroup($type, [$entityId => $fields]));
                        } catch (\Throwable $e) {
                            $onError($e, $this->entities[$entityId]);
                        }
                    }
                }
            }
        }

        $this->pending = [];
        $this->entities = [];
    }
}
