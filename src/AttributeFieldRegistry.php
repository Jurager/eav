<?php

namespace Jurager\Eav;

use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;
use InvalidArgumentException;

/**
 * Registry for attribute field types.
 *
 * Maps attribute type codes to their corresponding Field classes.
 * Registered as singleton in EavServiceProvider.
 */
class AttributeFieldRegistry
{
    /**
     * Mapping of field type codes to Field class names.
     * Loaded from config/eav.php, can be extended via register().
     *
     * @var array<string, class-string<Field>>
     */
    protected array $types;

    public function __construct(
        private readonly AttributeLocaleRegistry $localeRegistry
    ) {
        $this->types = config('eav.types', []);
    }

    /**
     * Register a new field type.
     *
     * @param string $type Field type code
     * @param class-string<Field> $class Field class name
     * @throws InvalidArgumentException If class does not extend Field
     */
    public function register(string $type, string $class): void
    {
        if (!is_subclass_of($class, Field::class)) {
            throw new InvalidArgumentException("Class '$class' must extend Field.");
        }

        $this->types[$type] = $class;
    }

    /**
     * Check if field type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Get field class by type code.
     *
     * @param string $type Field type code
     * @return class-string<Field>
     * @throws InvalidArgumentException If type is not registered
     */
    public function get(string $type): string
    {
        if (!$this->has($type)) {
            throw new InvalidArgumentException("Field type '$type' is not registered.");
        }

        return $this->types[$type];
    }

    /**
     * Create field instance from attribute.
     *
     * @param Attribute $attribute Attribute model
     * @return Field Field instance
     * @throws InvalidArgumentException If attribute type is not registered
     */
    public function make(Attribute $attribute): Field
    {
        $class = $this->get($attribute->type->code);

        return new $class($attribute, $this->localeRegistry);
    }

    /**
     * @return array<string, class-string<Field>>
     */
    public function all(): array
    {
        return $this->types;
    }
}
