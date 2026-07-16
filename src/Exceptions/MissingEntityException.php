<?php

namespace Jurager\Eav\Exceptions;

/** Exception thrown when an entity instance is missing. */
class MissingEntityException extends EavException
{
    /** Create a new exception for a missing entity. */
    public static function forManager(): self
    {
        return new self('Entity is required. Use "AttributeManager::for($entity)".');
    }
}
