<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Interface for resolving non-indexable filter keys. */
interface FilterResolver
{
    /**
     * Resolve a filter key that the search index cannot answer directly into an indexable substitute.
     *
     * @return array{0: string, 1: mixed}|null Array [key, value] to substitute, or null to leave unresolved.
     */
    public function resolve(string $name, mixed $value, Model $model): ?array;
}
