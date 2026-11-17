<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Contracts\Search\FilterResolver;
use Jurager\Eav\Exceptions\InvalidConfigurationException;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Search\Dialects\Dialect;
use Jurager\Eav\Search\Dialects\MeilisearchDialect;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Search\Index\IndexFactory;
use Jurager\Eav\Search\Testing\FakeDialect;
use Jurager\Eav\Search\Testing\FakeEngine;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\MeilisearchEngine;

/**
 * What search needs beside Scout's builder: federated searches, index definitions, and the dialects that
 * tell each Scout engine what Scout's query API cannot.
 */
class SearchManager
{
    /** @var array<class-string<Engine>, class-string<Dialect>|Closure(Container): Dialect> Dialects by Scout engine. */
    private array $dialects = [
        MeilisearchEngine::class => MeilisearchDialect::class,
        FakeEngine::class => FakeDialect::class,
    ];

    /** @var array<class-string<Engine>, Dialect> */
    private array $resolved = [];

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
     * Teach a Scout engine what Scout's query API cannot ask of it.
     *
     * @param  class-string<Engine>  $engine
     * @param  class-string<Dialect>|Closure(Container): Dialect  $dialect
     */
    public function extend(string $engine, string|Closure $dialect): static
    {
        $this->dialects[$engine] = $dialect;
        unset($this->resolved[$engine]);

        return $this;
    }

    /**
     * The dialect of a Scout engine: its own, or plain Scout for an engine that has none.
     */
    public function dialect(Engine $engine): Dialect
    {
        foreach ($this->dialects as $class => $dialect) {
            if ($engine instanceof $class) {
                return $this->resolved[$class] ??= $dialect instanceof Closure ? $dialect($this->container) : $this->container->make($dialect);
            }
        }

        return $this->resolved[Engine::class] ??= new Dialect;
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

    /**
     * The current locale, as the one a search runs in by default.
     *
     * @return list<string>
     */
    public function currentLocales(): array
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

    /**
     * Resolvers for filter keys the index does not hold.
     *
     * @return list<FilterResolver>
     */
    public function resolvers(): array
    {
        return array_map($this->container->make(...), (array) $this->container->make('config')->get('eav.search.resolvers', []));
    }
}
