<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Contracts;

interface InteractsWithIndex
{
    /** @return array<string, string> External filter key => indexed searchable field. */
    public function indexed(): array;
}
