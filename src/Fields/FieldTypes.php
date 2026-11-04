<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Enums\AttributeType;
use Jurager\Eav\Exceptions\InvalidFieldTypeException;

/**
 * The registered field classes keyed by attribute type code.
 */
class FieldTypes
{
    /** @var array<string, class-string<Field>> */
    private array $types = [];

    /** @param  array<string, class-string<Field>>  $types */
    public function __construct(array $types = [])
    {
        foreach ($types as $code => $class) {
            $this->register($code, $class);
        }
    }

    /** @param  class-string<Field>  $class */
    public function register(AttributeType|string $type, string $class): void
    {
        if (! is_subclass_of($class, Field::class)) {
            throw InvalidFieldTypeException::notAField($class);
        }

        $this->types[self::code($type)] = $class;
    }

    public function has(AttributeType|string $type): bool
    {
        return isset($this->types[self::code($type)]);
    }

    /** @return class-string<Field> */
    public function resolve(AttributeType|string $type): string
    {
        return $this->types[self::code($type)] ?? throw InvalidFieldTypeException::notRegistered(self::code($type));
    }

    /** @return array<string, class-string<Field>> */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Codes of the types whose field is, or extends, the given class.
     *
     * @param  class-string<Field>  $fieldClass
     * @return list<string>
     */
    public function using(string $fieldClass): array
    {
        return array_keys(array_filter($this->types, static fn (string $class): bool => is_a($class, $fieldClass, true)));
    }

    private static function code(AttributeType|string $type): string
    {
        return $type instanceof AttributeType ? $type->value : $type;
    }
}
