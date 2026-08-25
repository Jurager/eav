<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Builders\Attributes\AttributesFactory;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Managers\AttributeManager;

/**
 * Fluent access to schema-only attribute managers and batch persistence.
 *
 * Pass an entity type — an FQCN implementing `Attributable`, or its morph-map
 * key — to build a manager with no single entity in scope, for bulk lookups
 * and imports:
 *
 *     Attributes::for('product')->builder()->findBy('sku', 'ABC-123');
 *
 * For a live entity, call `eav()` on the entity itself instead; its manager
 * is cached on the instance, so repeated calls within a request share state:
 *
 *     $product->eav()->value('color');
 *     $product->eav()->set('color', 'red')->save('color');
 *
 * @method static AttributeManager for(string $entityType)
 * @method static AttributeManager schema(Attributable|Collection $entityOrAttributes)
 * @method static void sync(Collection $batch, ?AttributeManager $prebuiltSchema = null, int $chunkSize = 500, ?callable $onError = null, ?callable $onRejected = null)
 *
 * @see AttributesFactory
 */
class Attributes extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AttributesFactory::class;
    }
}
