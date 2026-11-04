<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Attributes\AttributeManager;
use Jurager\Eav\Attributes\AttributesFactory;
use Jurager\Eav\Attributes\EntitySchema;
use Jurager\Eav\Attributes\Query\AttributeQueryBuilder;
use Jurager\Eav\Contracts\Attributable;

/**
 * Manage and query entity attribute values.
 *
 * @method static AttributeManager for(Attributable $entity)
 * @method static EntitySchema schema(Attributable|string $entity)
 * @method static AttributeQueryBuilder query(string $entity)
 * @method static void sync(Collection $batch, ?EntitySchema $schema = null, int $chunkSize = 500, ?callable $onError = null, ?callable $onRejected = null)
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
