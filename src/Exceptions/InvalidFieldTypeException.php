<?php

namespace Jurager\Eav\Exceptions;

/** Exception thrown when a field type is invalid or missing. */
class InvalidFieldTypeException extends EavException
{
    /** Create a new exception when the class is not a valid field. */
    public static function notAField(string $class): self
    {
        return new self(sprintf('Class [%s] must extend the Field base class.', $class));
    }

    /** Create a new exception when the field type is not registered. */
    public static function notRegistered(string $type): self
    {
        return new self(sprintf('Field type [%s] is not registered.', $type));
    }

    /** Create a new exception when the type relation is missing. */
    public static function typeNotLoaded(string $code): self
    {
        return new self(sprintf('Attribute [%s] has no type loaded. Ensure the "type" relation is eager-loaded.', $code));
    }
}
