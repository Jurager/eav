<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Contracts;

use Jurager\Eav\Enums\IndexCapability;

interface InteractsWithIndex
{
    /** @return array<string, string> External filter key => indexed searchable field. */
    public function indexAliases(): array;

    /**
     * Index paths the engine must know about, and what it may do with each.
     *
     * @return array<string, list<IndexCapability>>
     */
    public function indexFields(): array;
}
