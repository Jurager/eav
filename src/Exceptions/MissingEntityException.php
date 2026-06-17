<?php

namespace Jurager\Eav\Exceptions;

/**
 * Thrown when an entity instance is required but was not provided.
 */
class MissingEntityException extends EavException
{
    public static function forManager(): self
    {
        return new self('Entity is required. Use AttributeManager::for($entity).');
    }

}
