<?php

namespace Jurager\Eav\Fields;

use Jurager\Eav\Exceptions\InvalidFieldTypeException;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Creates Field instances from Attribute models.
 * Supports registration of custom field types at runtime.
 */
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

    /**
     * Register a custom field type.
     *
     * @param  class-string<Field>  $class
     *
     * @throws InvalidFieldTypeException
     */
    public function register(string $type, string $class): void
    {
        if (! is_subclass_of($class, Field::class)) {
            throw InvalidFieldTypeException::notAField($class);
        }

        $this->types[$type] = $class;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @return class-string<Field>
     *
     * @throws InvalidFieldTypeException
     */
    public function resolve(string $type): string
    {
        if (! $this->has($type)) {
            throw InvalidFieldTypeException::notRegistered($type);
        }

        return $this->types[$type];
    }

    /**
     * @throws InvalidFieldTypeException
     */
    public function make(Attribute $attribute): Field
    {
        if ($attribute->type === null) {
            throw InvalidFieldTypeException::typeNotLoaded($attribute->code);
        }

        $class = $this->resolve($attribute->type->code);

        return new $class($attribute, $this->localeRegistry, $this->enumRegistry);
    }

    /** @return array<string, class-string<Field>> */
    public function all(): array
    {
        return $this->types;
    }
}
