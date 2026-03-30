<?php

namespace Jurager\Eav\Exceptions;

class SearchNotAvailableException extends EavException
{
    public static function scoutNotInstalled(): self
    {
        return new self('Full-text search requires Laravel Scout. Install laravel/scout and configure a search driver.');
    }
}
