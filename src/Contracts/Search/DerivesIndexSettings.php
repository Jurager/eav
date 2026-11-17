<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Search\Index\Index;

/**
 * A Scout engine whose index settings follow from the index definition.
 */
interface DerivesIndexSettings
{
    /**
     * The engine's settings of an index: what Scout is configured with, plus what the definition requires.
     *
     * @return array<string, mixed>
     */
    public function indexSettings(Index $index): array;
}
