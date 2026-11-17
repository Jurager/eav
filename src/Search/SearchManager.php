<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Manager;
use Jurager\Eav\Contracts\Search\Engine;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Search\Engines\MeilisearchEngine;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexFactory;
use Jurager\Eav\Search\Index\Indexer;
use Jurager\Eav\Search\Testing\FakeEngine;
use Meilisearch\Client;

/**
 * Entry point to search: builders for one entity type, or several federated into one result list.
 *
 * Searches run on the engine named by `eav.search.driver`; register your own with `extend()`.
 *
 * @method Engine driver(string|null $driver = null)
 */
class SearchManager extends Manager
{
    /**
     * Start a search over an entity type.
     *
     * @param  Model|class-string<Model>|string  $entity  A model, its class, or its morph alias.
     *
     * @throws SearchNotAvailableException When search is not installed or the model is not searchable.
     */
    public function for(Model|string $entity): Builder
    {
        return new Builder(
            $this->driver(),
            $this->container->make(IndexFactory::class)->for($entity),
            $this->container->make(Indexer::class),
            $this->container->make(FacetFormatter::class),
            array_map($this->container->make(...), (array) $this->config->get('eav.search.resolvers', [])),
            $this->currentLocales(),
        );
    }

    /**
     * Merge several searches into one list ranked across their entity types.
     *
     * @param  list<Builder>  $searches
     */
    public function federated(array $searches): FederatedBuilder
    {
        return new FederatedBuilder($this->driver(), $this->container->make(FacetFormatter::class), array_values($searches));
    }

    /**
     * The index definition of an entity type.
     */
    public function index(Model|string $entity): Index
    {
        return $this->container->make(IndexFactory::class)->for($entity);
    }

    /**
     * Answer searches with prepared hits instead of asking an engine, for tests.
     */
    public function fake(): FakeEngine
    {
        return $this->drivers[$this->getDefaultDriver()] = new FakeEngine;
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('eav.search.driver', 'meilisearch');
    }

    /**
     * @throws SearchNotAvailableException When Scout or the Meilisearch client is missing.
     */
    protected function createMeilisearchDriver(): Engine
    {
        if (! IndexFactory::available() || ! class_exists(Client::class)) {
            throw SearchNotAvailableException::notInstalled();
        }

        return $this->container->make(MeilisearchEngine::class);
    }

    /**
     * The current locale, as the one a search runs in by default.
     *
     * @return list<string>
     */
    private function currentLocales(): array
    {
        $locales = $this->container->make(LocaleRegistry::class);

        try {
            $code = $locales->code($locales->current());
        } catch (InvalidConfigurationException) {
            // No locales are set up: nothing is localized either.
            $code = null;
        }

        return $code === null ? [] : [$code];
    }
}
