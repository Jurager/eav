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

/**
 * Builds Field instances for attribute definitions.
 */
class FieldFactory
{
    public function __construct(
        private readonly FieldTypes $types,
        private readonly LocaleRegistry $localeRegistry,
        private readonly EnumRegistry $enumRegistry,
        private readonly AttributeRegistry $attributes,
    ) {}

    /**
     * Register a field type class.
     *
     * @param  class-string<Field>  $class
     */
    public function register(AttributeType|string $type, string $class): void
    {
        $this->types->register($type, $class);
    }

    /**
     * Check if a field type is registered.
     */
    public function has(AttributeType|string $type): bool
    {
        return $this->types->has($type);
    }

    /**
     * Resolve the class name for a given field type.
     *
     * @return class-string<Field>
     */
    public function resolve(AttributeType|string $type): string
    {
        return $this->types->resolve($type);
    }

    /**
     * Make a field instance from an attribute model.
     */
    public function make(Attribute $attribute): Field
    {
        if ($attribute->type === null) {
            throw InvalidFieldTypeException::typeNotLoaded($attribute->getAttribute('code'));
        }

        $class = $this->resolve($attribute->type->getAttribute('code'));

        return new $class($attribute, $this->localeRegistry, $this->enumRegistry);
    }

    /**
     * Get all registered field types.
     *
     * @return array<string, class-string<Field>>
     */
    public function all(): array
    {
        return $this->types->all();
    }

    /**
     * Get attributes using the field class or its subclasses, ignoring entity scope.
     *
     * @param  class-string<Field>  $fieldClass
     */
    public function using(string $fieldClass, string $entityType): Collection
    {
        $codes = $this->types->using($fieldClass);

        return $codes === [] ? collect() : $this->attributes->forTypes($entityType, $codes);
    }
}
