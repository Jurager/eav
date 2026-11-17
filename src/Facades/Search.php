<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Search\Builder;
use Jurager\Eav\Search\FederatedBuilder;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\SearchManager;
use Jurager\Eav\Search\Testing\FakeEngine;

/**
 * @method static Builder for(Model|string $entity)
 * @method static FederatedBuilder federated(array $searches)
 * @method static Index index(Model|string $entity)
 * @method static FakeEngine fake()
 * @method static Engine driver(string|null $driver = null)
 * @method static SearchManager extend(string $driver, \Closure $callback)
 *
 * @see SearchManager
 */
class Search extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SearchManager::class;
    }
}
