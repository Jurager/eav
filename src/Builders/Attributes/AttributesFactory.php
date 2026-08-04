<?php

declare(strict_types=1);

namespace Jurager\Eav\Builders\Attributes;

use Illuminate\Support\Collection;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Managers\AttributeManager;

/** Resolves schema-only attribute managers, exposed via the Attributes facade. */
class AttributesFactory
{
    /**
     * Build a schema-only manager for an entity type — an FQCN implementing
     * `Attributable`, or its morph-map key. For a live entity, call `eav()` on
     * the entity itself instead; its manager is cached on the instance.
     */
    public function for(string $entityType): AttributeManager
    {
        return AttributeManager::for($entityType);
    }

    /** Build a schema-only manager for an entity or a preloaded attribute collection. */
    public function schema(Attributable|Collection $entityOrAttributes): AttributeManager
    {
        return AttributeManager::schema($entityOrAttributes);
    }

    /**
     * Persist attribute values for multiple entities in chunked batches.
     *
     * @param  Collection<int, array{entity: Attributable, data: array<string, mixed>}>  $batch
     */
    public function sync(Collection $batch, ?AttributeManager $prebuiltSchema = null, int $chunkSize = 500, ?callable $onError = null): void
    {
        AttributeManager::sync($batch, $prebuiltSchema, $chunkSize, $onError);
    }
}
