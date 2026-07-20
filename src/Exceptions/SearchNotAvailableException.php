<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

/** Exception thrown when full-text search is requested but not configured. */
class SearchNotAvailableException extends EavException
{
    /** Create a new exception for missing Laravel Scout. */
    public static function scoutNotInstalled(): self
    {
        return new self('Full-text search requires Laravel Scout. Install "laravel/scout" and configure a search driver.');
    }
}
