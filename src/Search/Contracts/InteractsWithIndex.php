<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Contracts;

interface InteractsWithIndex
{
    /** @return array<string, string> External filter key => indexed searchable field. */
    public function indexAliases(): array;

    /** @return list<string> Extra index paths that should be filterable / facetable in the search engine. */
    public function indexFilters(): array;
}
