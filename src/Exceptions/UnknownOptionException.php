<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

/**
 * A select was given an option code its attribute does not have.
 */
class UnknownOptionException extends EavException
{
    public static function code(string $code, string $attributeCode): self
    {
        return new self(sprintf('Option [%s] does not exist for attribute [%s].', $code, $attributeCode));
    }
}
