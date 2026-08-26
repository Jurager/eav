<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Jurager\Eav\Enums\AttributeType;
use Jurager\Eav\Exceptions\InvalidFieldTypeException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

class FieldFactory
{
    /** @var array<string, class-string<Field>> */
    protected array $types;

    public function __construct(
        private readonly LocaleRegistry $localeRegistry,
        private readonly EnumRegistry $enumRegistry,
    ) {
        $this->types = config('eav.types', []);
    }

    /** Register a new field type class. */
    public function register(AttributeType|string $type, string $class): void
    {
        if (! is_subclass_of($class, Field::class)) {
            throw InvalidFieldTypeException::notAField($class);
        }

        $this->types[$this->getTypeCode($type)] = $class;
    }

    /** Check if a field type is registered. */
    public function has(AttributeType|string $type): bool
    {
        return isset($this->types[$this->getTypeCode($type)]);
    }

    /** Resolve a class name for a given field type. */
    public function resolve(AttributeType|string $type): string
    {
        $code = $this->getTypeCode($type);

        if (! $this->has($code)) {
            throw InvalidFieldTypeException::notRegistered($code);
        }

        return $this->types[$code];
    }

    /** Make a field instance from an attribute model. */
    public function make(Attribute $attribute): Field
    {
        if ($attribute->type === null) {
            throw InvalidFieldTypeException::typeNotLoaded($attribute->getAttribute('code'));
        }

        $class = $this->resolve($attribute->type->getAttribute('code'));

        return new $class($attribute, $this->localeRegistry, $this->enumRegistry);
    }

    /** Get all registered field types. */
    public function all(): array
    {
        return $this->types;
    }

    /** Get the string code from a type enum or string. */
    private function getTypeCode(AttributeType|string $type): string
    {
        return $type instanceof AttributeType ? $type->value : $type;
    }
}
