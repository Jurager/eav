<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * A filter or sort rejected by the search engine; rendered as 422.
 */
class InvalidSearchQueryException extends UnprocessableEntityHttpException
{
    public static function rejected(string $reason, ?Throwable $previous = null): self
    {
        return new self("Invalid search request: {$reason}", $previous);
    }
}
