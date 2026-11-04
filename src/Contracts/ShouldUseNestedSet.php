<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts;

/**
 * Use nested-set bounds to resolve inherited attributes.
 */
interface ShouldUseNestedSet extends Attributable
{
    /** @return string The column holding the left bound. */
    public function getLftName();

    /** @return string The column holding the right bound. */
    public function getRgtName();
}
