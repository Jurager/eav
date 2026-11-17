<?php

declare(strict_types=1);

namespace Jurager\Eav\Search\Filters;

use Jurager\Eav\Enums\Comparison;
use Jurager\Eav\Search\Index\IndexField;

/**
 * One checked condition of a search: a filterable field, a comparison, and a value of the field's type.
 */
final readonly class Condition
{
    /**
     * @param  string  $name  The name the request called the field by, for error messages.
     * @param  mixed  $value  A scalar; a list for `In` and `NotIn`; `[min, max]` for the between pair;
     *                        null for `Exists` and `Missing`.
     */
    public function __construct(
        public IndexField $field,
        public Comparison $comparison,
        public mixed $value,
        public string $name,
    ) {}
}
