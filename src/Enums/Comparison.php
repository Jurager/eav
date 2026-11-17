<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

/**
 * How a search condition compares a field with its value.
 */
enum Comparison: string
{
    case Equal = 'eq';
    case NotEqual = 'ne';
    case Greater = 'gt';
    case GreaterOrEqual = 'gte';
    case Less = 'lt';
    case LessOrEqual = 'lte';
    case In = 'in';
    case NotIn = 'nin';
    case Between = 'between';
    case NotBetween = 'not_between';
    case Exists = 'not_null';
    case Missing = 'null';
    case Contains = 'like';

    /**
     * Whether the comparison only makes sense on a field compared by magnitude.
     */
    public function isOrdering(): bool
    {
        return in_array($this, [self::Greater, self::GreaterOrEqual, self::Less, self::LessOrEqual, self::Between, self::NotBetween], true);
    }
}
