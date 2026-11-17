<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

/**
 * Search was requested but is not set up.
 */
class SearchNotAvailableException extends EavException
{
    /**
     * Laravel Scout or the Meilisearch client is missing.
     */
    public static function notInstalled(): self
    {
        return new self('Search requires "laravel/scout" and "meilisearch/meilisearch-php", with Scout\'s meilisearch driver configured.');
    }

    /**
     * The entity is not a model using HasSearchableAttributes.
     */
    public static function notSearchable(string $entity): self
    {
        return new self("[{$entity}] is not searchable. Add the HasSearchableAttributes trait to its model.");
    }
}
