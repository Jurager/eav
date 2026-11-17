<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Search\Engines\FakeEngine;
use Jurager\Eav\Search\Engines\MeilisearchEngine;
use Jurager\Eav\Search\Grammars\MeilisearchGrammar;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexFactory;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\MeilisearchEngine as ScoutMeilisearchEngine;
use Meilisearch\Client;

/**
 * What search needs beside Scout's builder: federated searches, index definitions, and the engine
 * entities are searched with.
 */
class SearchManager
{
    private ?MeilisearchEngine $meilisearch = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Merge searches over several entity types into one list ranked across them.
     *
     * @param  list<Builder>  $searches
     */
    public function federated(array $searches): FederatedBuilder
    {
        return new FederatedBuilder($this->container->make(FacetFormatter::class), array_values($searches));
    }

    /**
     * The index definition of an entity type.
     *
     * @param  Model|class-string<Model>|string  $entity  A model, its class, or its morph alias.
     *
     * @throws SearchNotAvailableException When the model is not searchable.
     */
    public function index(Model|string $entity): Index
    {
        return $this->container->make(IndexFactory::class)->for($entity);
    }

    /**
     * The engine entities are indexed and searched with: the one Scout is configured with, in its extended
     * form where the package has one.
     */
    public function engine(): Engine
    {
        $engine = $this->container->make(EngineManager::class)->engine();

        // Scout's Meilisearch engine exactly: an engine extending it already is what it means to be.
        if ($engine::class !== ScoutMeilisearchEngine::class) {
            return $engine;
        }

        return $this->meilisearch ??= new MeilisearchEngine(
            $this->container->make(Client::class),
            (bool) $this->container->make('config')->get('scout.soft_delete', false),
            $this->container->make(MeilisearchGrammar::class),
        );
    }

    /**
     * Answer searches with prepared hits instead of asking a search engine, for tests.
     */
    public function fake(): FakeEngine
    {
        $fake = new FakeEngine;

        $this->container->make(EngineManager::class)->extend('eav-fake', static fn (): FakeEngine => $fake);
        $this->container->make('config')->set('scout.driver', 'eav-fake');

        return $fake;
    }
}
