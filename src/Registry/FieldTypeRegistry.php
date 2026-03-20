<?php

namespace Jurager\Eav\Registry;

use InvalidArgumentException;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Models\Attribute;

/**
 * Maps attribute type codes to Field classes.
 *
 * Registered as a singleton in EavServiceProvider.
 * Pre-populated from config/eav.php, extensible via register().
 */
class FieldTypeRegistry
{
    /** @var array<string, class-string<Field>> */
    protected array $types;

    public function __construct(private readonly LocaleRegistry $localeRegistry)
    {
        $this->types = config('eav.types', []);
    }

    /**
     * Register a custom field type.
     *
     * @param  class-string<Field>  $class
     *
     * @throws InvalidArgumentException
     */
    public function register(string $type, string $class): void
    {
        if (! is_subclass_of($class, Field::class)) {
            throw new InvalidArgumentException("Class '$class' must extend Field.");
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
     * @throws InvalidArgumentException
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
     * @throws InvalidArgumentException
     */
    public function make(Attribute $attribute): Field
    {
        $class = $this->resolve($attribute->type->code);

        return new $class($attribute, $this->localeRegistry);
    }

    /** @return array<string, class-string<Field>> */
    public function all(): array
    {
        return $this->types;
    }
}
