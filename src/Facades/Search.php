<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Search\Dialects\Dialect;
use Jurager\Eav\Search\FederatedBuilder;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\SearchManager;
use Jurager\Eav\Search\Testing\FakeEngine;
use Laravel\Scout\Engines\Engine;

/**
 * @method static FederatedBuilder federated(array $searches)
 * @method static Index index(Model|string $entity)
 * @method static SearchManager extend(string $engine, string|Closure $dialect)
 * @method static Dialect dialect(Engine $engine)
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
