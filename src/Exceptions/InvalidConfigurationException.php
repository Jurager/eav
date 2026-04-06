<?php

namespace Jurager\Eav\Exceptions;

/**
 * Thrown when the EAV package is misconfigured or a required contract is missing.
 */
class InvalidConfigurationException extends EavException
{
    public static function modelNotConfigured(string $key): self
    {
        return new self("EAV model '$key' is not configured in eav.models.");
    }

    public static function localeNotFound(string $code): self
    {
        return new self("Default locale \"$code\" not found in the locales table. Add it or update app.locale.");
    }

    public static function missingAttributableContract(string $class): self
    {
        return new self("$class must implement Attributable.");
    }

}
