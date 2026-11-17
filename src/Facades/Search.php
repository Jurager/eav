<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\FederatedBuilder;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Transport\FakeTransport;

/**
 * @method static Builder for(Model|string $entity)
 * @method static FederatedBuilder federated(array $searches)
 * @method static Index index(Model|string $entity)
 * @method static FakeTransport fake()
 *
 * @see \Jurager\Eav\Search\Search
 */
class Search extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Jurager\Eav\Search\Search::class;
    }
}
