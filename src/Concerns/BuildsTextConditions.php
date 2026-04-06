<?php

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Case-insensitive query helpers shared between AttributeManager and app-level filter traits.
 *
 * All string comparisons use LOWER() on both sides so they work correctly for any charset,
 * including Cyrillic. Numeric strings (e.g. '5') are treated as non-text and bypass LOWER()
 * to avoid PostgreSQL errors on integer/float columns.
 */
trait BuildsTextConditions
{
    /**
     * Apply = or != with LOWER() for text values, plain where() for numerics.
     */
    private function applyScalarCondition(Builder|QueryBuilder $query, string $column, string $sqlOp, mixed $value): void
    {
        if ($this->isTextValue($value)) {
            $query->whereRaw('LOWER('.$column.') '.$sqlOp.' ?', [mb_strtolower($value)]);
        } else {
            $query->where($column, $sqlOp, $value);
        }
    }

    /**
     * Apply LOWER() LIKE with automatic wildcard wrapping and special-char escaping.
     * No-op if $value is not a string.
     */
    private function applyLike(Builder|QueryBuilder $query, string $column, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($escaped).'%']);
    }

    /**
     * Apply IN with LOWER() when the values contain at least one non-numeric string.
     */
    private function applyInLower(Builder|QueryBuilder $query, string $column, array $values): void
    {
        if (array_any($values, fn ($v) => $this->isTextValue($v))) {
            $lower = array_map(fn ($v) => is_string($v) ? mb_strtolower($v) : $v, $values);
            $placeholders = implode(',', array_fill(0, count($lower), '?'));
            $query->whereRaw('LOWER('.$column.') IN ('.$placeholders.')', array_values($lower));
        } else {
            $query->whereIn($column, $values);
        }
    }

    /**
     * Apply NOT IN with LOWER() when the values contain at least one non-numeric string.
     */
    private function applyNotInLower(Builder|QueryBuilder $query, string $column, array $values): void
    {
        if (array_any($values, fn ($v) => $this->isTextValue($v))) {
            $lower = array_map(fn ($v) => is_string($v) ? mb_strtolower($v) : $v, $values);
            $placeholders = implode(',', array_fill(0, count($lower), '?'));
            $query->whereRaw('LOWER('.$column.') NOT IN ('.$placeholders.')', array_values($lower));
        } else {
            $query->whereNotIn($column, $values);
        }
    }

    /**
     * Returns true for non-numeric strings — the only values that warrant LOWER().
     * Numeric strings ('5', '3.14') represent integer/float columns and must not use LOWER().
     */
    private function isTextValue(mixed $value): bool
    {
        return is_string($value) && ! is_numeric($value);
    }
}
