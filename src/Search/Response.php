<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

/**
 * What a federated search found: hits, how many there are in all, and facet data keyed by entity type.
 */
final readonly class Response
{
    /**
     * @param  list<Hit>  $hits
     * @param  array<string, mixed>  $facets  A map of value to count, or `min` and `max`, per facet name.
     * @param  bool  $formatted  Whether the facets already carry labels and their final shape.
     */
    public function __construct(
        public array $hits = [],
        public int $total = 0,
        public array $facets = [],
        public bool $formatted = false,
    ) {}
}
