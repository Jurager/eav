<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

/**
 * How a field is stored in the search index, and what the engine may do with it.
 */
enum IndexType: string
{
    case Text = 'text';
    case Keyword = 'keyword';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    /**
     * Whether the field is compared by magnitude: ranges, min/max facets.
     */
    public function isNumeric(): bool
    {
        return $this === self::Number || $this === self::Date;
    }

    /**
     * Whether a misspelt query word may still match the field.
     */
    public function toleratesTypos(): bool
    {
        return $this === self::Text;
    }

    /**
     * Cast a value to what the index holds for this type, or null when it cannot be one.
     */
    public function cast(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        return match ($this) {
            self::Text, self::Keyword => is_scalar($value) || $value instanceof \Stringable ? (string) $value : null,
            self::Number => is_numeric($value) ? $value + 0 : null,
            self::Boolean => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            self::Date => self::timestamp($value),
        };
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->getTimestamp() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
