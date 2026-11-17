<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * A search request that cannot be answered as asked; rendered as 422.
 */
class InvalidSearchQueryException extends UnprocessableEntityHttpException
{
    public static function rejected(string $reason, ?Throwable $previous = null): self
    {
        return new self("Invalid search request: {$reason}", $previous);
    }

    public static function unknownField(string $name): self
    {
        return self::rejected("field [{$name}] is not part of the search index.");
    }

    public static function unknownLocale(string $locale): self
    {
        return self::rejected("locale [{$locale}] is not available.");
    }

    public static function notSearchable(string $name): self
    {
        return self::rejected("field [{$name}] is not searchable.");
    }

    public static function notFilterable(string $name): self
    {
        return self::rejected("field [{$name}] is not filterable.");
    }

    public static function notSortable(string $name): self
    {
        return self::rejected("field [{$name}] is not sortable.");
    }

    public static function unsupportedOperator(string $name, string $operator): self
    {
        return self::rejected("operator [{$operator}] is not supported on field [{$name}].");
    }

    public static function invalidValue(string $name, string $operator): self
    {
        return self::rejected("value of [{$name}] is not valid for operator [{$operator}].");
    }

    public static function tooManyMatches(string $name, int $limit): self
    {
        return self::rejected("filter [{$name}] matches more than {$limit} related records; narrow it down.");
    }
}
