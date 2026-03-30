<?php

namespace Jurager\Eav\Exceptions;

/**
 * Thrown when a field type is invalid, unregistered, or missing its type relation.
 */
class InvalidFieldTypeException extends EavException
{
    public static function notAField(string $class): self
    {
        return new self("Class '$class' must extend Field.");
    }

    public static function notRegistered(string $type): self
    {
        return new self("Field type '$type' is not registered.");
    }

    public static function typeNotLoaded(string $code): self
    {
        return new self("Attribute '$code' has no type loaded. Ensure 'type' relation is eager-loaded.");
    }
}
