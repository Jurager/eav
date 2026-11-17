<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

/**
 * What an engine found: hits, how many there are in all, and facet data keyed by the asked names.
 */
final readonly class Response
{
    /**
     * @param  list<Hit>  $hits
     * @param  array<string, mixed>  $facets  A map of value to count, or `min` and `max`, per facet name.
     */
    public function __construct(
        public array $hits = [],
        public int $total = 0,
        public array $facets = [],
    ) {}
}
