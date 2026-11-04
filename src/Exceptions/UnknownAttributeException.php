<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

/**
 * An attribute code the entity does not have was written to.
 */
class UnknownAttributeException extends EavException
{
    public static function code(string $code, string $entityType): self
    {
        return new self(sprintf('Attribute [%s] is not available to entities of type [%s].', $code, $entityType));
    }
}
