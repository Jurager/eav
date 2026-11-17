<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Contracts\Transport;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexFactory;
use Jurager\Eav\Search\Transport\FakeTransport;

/**
 * Entry point to search: builders for one entity type, or several federated into one result list.
 */
class Search
{
    public function __construct(
        private readonly Container $container,
        private readonly IndexFactory $indexes,
        private readonly LocaleRegistry $locales,
    ) {}

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
            $this->container->make(Engine::class),
            $this->indexes->for($entity),
            array_map($this->container->make(...), (array) $this->container->make('config')->get('eav.search.resolvers', [])),
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
        return new FederatedBuilder($this->container->make(Engine::class), array_values($searches));
    }

    /**
     * The index definition of an entity type.
     */
    public function index(Model|string $entity): Index
    {
        return $this->indexes->for($entity);
    }

    /**
     * The current locale, as the one a search runs in by default.
     *
     * @return list<string>
     */
    private function currentLocales(): array
    {
        try {
            $code = $this->locales->code($this->locales->current());
        } catch (InvalidConfigurationException) {
            // No locales are set up: nothing is localized either.
            $code = null;
        }

        return $code === null ? [] : [$code];
    }

    /**
     * Record searches instead of sending them to the engine, for tests.
     */
    public function fake(): FakeTransport
    {
        $this->container->instance(Transport::class, $fake = new FakeTransport);

        return $fake;
    }
}
