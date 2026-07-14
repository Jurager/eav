<?php

namespace Jurager\Eav\Search\Contracts;

/**
 * Declares which of a model's non-EAV (base) fields are also present in its
 * Meilisearch index, and what they're called there — so Search can resolve
 * filter keys for them the same way it already does for EAV attribute facets.
 */
interface InteractsWithIndex
{
    /** @return array<string, string> External filter key => indexed Meilisearch field. */
    public function indexed(): array;
}
