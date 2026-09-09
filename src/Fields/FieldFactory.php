<?php

declare(strict_types=1);

namespace Jurager\Eav\Fields;

use Illuminate\Support\Collection;
use Jurager\Eav\Enums\AttributeType;
use Jurager\Eav\Exceptions\InvalidFieldTypeException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

class FieldFactory
{
    /** @var array<string, class-string<Field>> */
    protected array $types;

    public function __construct(
        private readonly LocaleRegistry $localeRegistry,
        private readonly EnumRegistry $enumRegistry,
        private readonly AttributeRegistry $attributes,
    ) {
        $this->types = config('eav.types', []);
    }

    /** Register a new field type class. */
    public function register(AttributeType|string $type, string $class): void
    {
        if (! is_subclass_of($class, Field::class)) {
            throw InvalidFieldTypeException::notAField($class);
        }

        $this->types[$this->type($type)] = $class;
    }

    /** Check if a field type is registered. */
    public function has(AttributeType|string $type): bool
    {
        return isset($this->types[$this->type($type)]);
    }

    /** Resolve a class name for a given field type. */
    public function resolve(AttributeType|string $type): string
    {
        $code = $this->type($type);

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

    /** Attributes of $entityType whose field class is, or extends, $fieldClass — schema-level, ignores instance scope. */
    public function using(string $fieldClass, string $entityType): Collection
    {
        $codes = array_keys(array_filter(
            $this->types,
            fn (string $class) => is_a($class, $fieldClass, true),
        ));

        if (empty($codes)) {
            return collect();
        }

        return $this->attributes->all($entityType)
            ->filter(fn (Attribute $attribute) => in_array($attribute->type?->getAttribute('code'), $codes, true));
    }

    /** Get the string code from a type enum or string. */
    private function type(AttributeType|string $type): string
    {
        return $type instanceof AttributeType ? $type->value : $type;
    }
}
