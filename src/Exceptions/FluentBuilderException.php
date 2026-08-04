<?php

declare(strict_types=1);

namespace Jurager\Eav\Exceptions;

/** Exception thrown when a fluent builder (Schema, Translator) cannot resolve a referenced code or is used in the wrong mode. */
class FluentBuilderException extends EavException
{
    /** Create a new exception for an unregistered attribute type code. */
    public static function unknownType(string $code): self
    {
        return new self(sprintf('Attribute type [%s] is not registered. Seed it before referencing it in the schema builder.', $code));
    }

    /** Create a new exception for a missing attribute group code. */
    public static function unknownGroup(string $code): self
    {
        return new self(sprintf('Attribute group [%s] does not exist. Create it before referencing it in the schema builder.', $code));
    }

    /** Create a new exception for an unregistered locale code. */
    public static function unknownLocale(string $code): self
    {
        return new self(sprintf('Locale [%s] is not registered.', $code));
    }

    /** Create a new exception when ->create() is called before ->type() on an attribute builder. */
    public static function missingType(string $code): self
    {
        return new self(sprintf('Attribute [%s] has no type set. Call ->type($code) before ->create().', $code));
    }

    /** Create a new exception for a builder constructed without an entity type for a new attribute. */
    public static function missingEntityType(string $code): self
    {
        return new self(sprintf('Schema::attribute(\'%s\', $entityType) needs an entity type to create a new attribute.', $code));
    }

    /** Create a new exception for an enum builder constructed without a code for a new enum. */
    public static function missingCode(): self
    {
        return new self('Schema::enum($attribute, $code) needs a code to create a new enum option.');
    }

    /** Create a new exception when ->create() is called on a builder constructed from an existing record. */
    public static function cannotCreateFromExisting(): self
    {
        return new self('This builder was constructed from an existing record — call ->update() instead of ->create().');
    }

    /** Create a new exception when ->update() is called on a builder constructed for a new record. */
    public static function cannotUpdateWithoutExisting(): self
    {
        return new self('This builder was constructed from a code, not an existing record — call ->create() instead, or pass the existing model to build an update.');
    }
}
