<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

use Jurager\Eav\Search\Index\Index;

/**
 * An engine whose indexes must be told how their fields are used before they can be searched.
 */
interface UpdatesIndexSettings
{
    /**
     * Bring the engine's settings of an index in line with its definition.
     */
    public function updateIndexSettings(Index $index): void;
}
