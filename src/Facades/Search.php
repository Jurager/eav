<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Search\Engines\FakeEngine;
use Jurager\Eav\Search\FederatedBuilder;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\SearchManager;
use Laravel\Scout\Engines\Engine;

/**
 * @method static FederatedBuilder federated(array $searches)
 * @method static Index index(Model|string $entity)
 * @method static Engine engine()
 * @method static FakeEngine fake()
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
