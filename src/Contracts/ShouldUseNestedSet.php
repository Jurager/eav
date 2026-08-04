<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts;

/**
 * Opts an entity into nested-set attribute inheritance resolution — ancestors
 * are resolved in a single bounds query instead of walking `parent_id` level
 * by level. Requires `_lft`/`_rgt` columns, for example via `kalnoy/nestedset`.
 */
interface ShouldUseNestedSet extends Attributable
{
    //
}
