<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Closure;

/** Registry for non-EAV filterable index paths. */
class FilterableRegistry
{
    /**
     * Map of model classes to their extra index path resolvers.
     *
     * @var array<class-string, list<Closure(): list<string>>>
     */
    private array $resolvers = [];

    /** Register a resolver contributing extra filterable index paths for a model class. */
    public function register(string $modelClass, Closure $resolver): void
    {
        $this->resolvers[$modelClass][] = $resolver;
    }

    /**
     * Resolve all extra filterable index paths for a model class.
     *
     * @return list<string>
     */
    public function resolve(string $modelClass): array
    {
        $paths = [];

        foreach ($this->resolvers[$modelClass] ?? [] as $resolver) {
            $paths = array_merge($paths, $resolver());
        }

        return array_values(array_unique($paths));
    }
}