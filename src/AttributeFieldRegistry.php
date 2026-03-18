<?php

namespace Jurager\Eav;

use InvalidArgumentException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;

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
     * @param  string               $type   Field type code.
     * @param  class-string<Field>  $class  Field class name.
     *
     * @throws InvalidArgumentException  If class does not extend Field.
     */
    public function register(string $type, string $class): void
    {
        if (! is_subclass_of($class, Field::class)) {
            throw new InvalidArgumentException("Class '$class' must extend Field.");
        }

        $this->types[$type] = $class;
    }

    /**
     * Determine if a field type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Resolve the Field class name for a given type code.
     *
     * @param   string  $type  Field type code.
     * @return  class-string<Field>
     *
     * @throws InvalidArgumentException  If the type is not registered.
     */
    public function resolve(string $type): string
    {
        if (! $this->has($type)) {
            throw new InvalidArgumentException("Field type '$type' is not registered.");
        }

        return $this->types[$type];
    }

    /**
     * Create a Field instance from an Attribute model.
     *
     * @param   Attribute  $attribute  Attribute model.
     * @return  Field
     *
     * @throws InvalidArgumentException  If the attribute type is not registered.
     */
    public function make(Attribute $attribute): Field
    {
        $class = $this->resolve($attribute->type->code);

        return new $class($attribute, $this->localeRegistry);
    }

    /**
     * Return all registered type mappings.
     *
     * @return array<string, class-string<Field>>
     */
    public function all(): array
    {
        return $this->types;
    }
}
