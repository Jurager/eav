<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Jurager\Eav\Attributes\Persistence\AttributePersister;
use Jurager\Eav\Attributes\Persistence\BatchAttributePersister;
use Jurager\Eav\Attributes\Query\AttributeQueryBuilder;
use Jurager\Eav\Attributes\Query\AttributeQueryFactory;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Throwable;

/**
 * Create attribute managers, schemas, and queries.
 */
class AttributesFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly AttributeQueryFactory $queries,
    ) {}

    /**
     * Get the attribute manager for an entity.
     */
    public function for(Attributable $entity): AttributeManager
    {
        return $this->container->make(AttributeManager::class, [
            'entity' => $entity,
            'schema' => $this->schema($entity),
            'values' => $this->container->make(EntityValues::class, ['entity' => $entity]),
            'persister' => $this->container->make(AttributePersister::class, ['entity' => $entity]),
        ]);
    }

    /**
     * The attribute definitions of an entity's scope, or of a whole entity type.
     *
     * @param  Attributable|class-string<Attributable>|string  $entity  An entity, its class, or its morph alias.
     */
    public function schema(Attributable|string $entity): EntitySchema
    {
        return $this->container->make(EntitySchema::class, $entity instanceof Attributable
            ? ['entityType' => $entity->getEntityType(), 'scope' => $entity]
            : ['entityType' => $this->entityType($entity)]);
    }

    /**
     * Create a query for entities matching attribute values.
     *
     * @param  class-string<Attributable>|string  $entity  The entity class or its morph alias.
     */
    public function query(string $entity): AttributeQueryBuilder
    {
        $model = class_exists($entity) ? $entity : Relation::getMorphedModel($entity);

        return $this->queries->for($this->entityType($entity), $model);
    }

    /**
     * Persist attribute values for many entities in chunked batches.
     *
     * @param  Collection<int, array{entity: Attributable, data: array<string, mixed>}>  $batch
     * @param  EntitySchema|null  $schema  Definitions shared by every entity of the batch; each entity's own scope otherwise.
     * @param  (callable(Throwable, Attributable): void)|null  $onError  Invoked when an entity fails to persist; the rest continue.
     * @param  (callable(Attributable, string): void)|null  $onRejected  Invoked for codes the other side of a parent/variant pair holds.
     */
    public function sync(
        Collection $batch,
        ?EntitySchema $schema = null,
        int $chunkSize = 500,
        ?callable $onError = null,
        ?callable $onRejected = null,
    ): void {
        foreach ($batch->chunk(max(1, $chunkSize)) as $chunk) {
            $persister = $this->container->make(BatchAttributePersister::class);

            foreach ($chunk as ['entity' => $entity, 'data' => $data]) {
                // Only filled fields are written; an explicitly blank value is the field's own concern.
                $fields = ($schema ?? $this->schema($entity))
                    ->fill($data, $entity, $onRejected !== null ? static fn (string $code) => $onRejected($entity, $code) : null)
                    ->filter->isFilled();

                if ($fields->isNotEmpty()) {
                    $persister->add($entity, $fields);
                }
            }

            $persister->flush($onError);
        }
    }

    /**
     * The entity type of an entity class, or the morph alias given as is.
     */
    private function entityType(string $entity): string
    {
        if (! class_exists($entity)) {
            return $entity;
        }

        if (! is_subclass_of($entity, Attributable::class)) {
            throw InvalidConfigurationException::missingAttributableContract($entity);
        }

        return (new $entity)->getEntityType();
    }
}
