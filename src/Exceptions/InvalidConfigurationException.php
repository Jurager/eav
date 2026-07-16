<?php

namespace Jurager\Eav\Exceptions;

/** Exception thrown when the EAV package configuration is invalid. */
class InvalidConfigurationException extends EavException
{
    /** Create a new exception for a missing locale. */
    public static function localeNotFound(string $code): self
    {
        return new self(sprintf('Default locale [%s] not found in the locales table. Add it or update "app.locale".', $code));
    }

    /** Create a new exception for a missing Attributable contract. */
    public static function missingAttributableContract(string $class): self
    {
        return new self(sprintf('Class [%s] must implement the Attributable contract.', $class));
    }
}
