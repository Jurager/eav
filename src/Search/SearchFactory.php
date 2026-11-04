<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Container\Container;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Meilisearch\Client;

class SearchFactory
{
    /**
     * @param  iterable<Contracts\FilterResolver>  $resolvers
     */
    public function __construct(
        private readonly Container $container,
        private readonly iterable $resolvers,
    ) {}

    /**
     * Create search builder for entity type.
     *
     * @throws SearchNotAvailableException When meilisearch/meilisearch-php is not installed.
     */
    public function for(string $entityType): Builder
    {
        if (! class_exists(Client::class)) {
            throw SearchNotAvailableException::meilisearchNotInstalled();
        }

        return new Builder($this->container->make(Engine::class), $this->resolvers, $entityType);
    }
}
