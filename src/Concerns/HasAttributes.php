<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Attributes\AttributeManager;
use Jurager\Eav\Attributes\AttributesFactory;
use Jurager\Eav\Attributes\Validation\AttributeValidator;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\AttributeStorage;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Relations\Concerns\HasClosureRelations;

/**
 * @property Collection $assignedAttributes
 *
 * @phpstan-require-implements Attributable
 */
trait HasAttributes
{
    use HasAttributeScope;
    use HasAttributeQueryScopes;
    use HasAttributeVariants;
    use HasClosureRelations;

    /**
     * Cached AttributeManager instance for this model.
     */
    protected ?AttributeManager $attributeManager = null;

    /**
     * Boot trait attributes.
     */
    public static function bootHasAttributes(): void
    {
        // Saving the entity saves the attribute values set on it, like its own columns.
        static::saved(static function (Model $model): void {
            if ($model->attributeManager?->isDirty()) {
                $model->attributeManager->save();
            }
        });

        static::resolveRelationUsing('attribute_values', fn ($model) => $model->attributeValues());

        static::resolveRelationUsing('available_attributes', fn (Model $model) => $model->availableAttributesRelation(
            fn (Model $entity) => $entity->getEditableAttributesQuery($entity->attributeScopeIds()),
        ));
    }

    /**
     * Declare scoped uniqueness for specific attributes.
     *
     * @return array<string, callable>
     */
    public static function attributeUniqueScopes(): array
    {
        return [];
    }

    /**
     * Get cached attribute manager instance.
     */
    public function eav(): AttributeManager
    {
        return $this->attributeManager ??= app(AttributesFactory::class)->for($this);
    }

    /**
     * Get the entity type identifier, defaulting to the Eloquent morph class.
     */
    public function getEntityType(): string
    {
        return $this->getMorphClass();
    }

    /**
     * Reload the entity, attribute values included.
     */
    public function refresh()
    {
        $this->attributeManager = null;

        return parent::refresh();
    }

    /**
     * A copy reads and writes values of its own, not through the original's manager.
     */
    public function __clone(): void
    {
        $this->attributeManager = null;
    }

    /**
     * Fallback to EAV attribute reading for undefined properties.
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($value !== null || ! is_string($key) || $key === '') {
            return $value;
        }

        if ($this->isRealColumn($key) || array_key_exists($key, $this->relations)) {
            return $value;
        }

        return $this->eav()->value($key);
    }

    /**
     * Fallback to EAV attribute assignment for undefined properties.
     */
    public function setAttribute($key, $value)
    {
        if (
            is_string($key) && $key !== ''
            && ! $this->hasSetMutator($key)
            && ! $this->hasAttributeSetMutator($key)
            && ! $this->isRelation($key)
            && ! $this->isRealColumn($key)
            && $this->eav()->field($key) !== null
        ) {
            $this->eav()->set($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Check if key is a real database column.
     */
    private function isRealColumn(string $key): bool
    {
        static $columns = [];

        $table = $this->getTable();

        return in_array($key, $columns[$table] ??= $this->getConnection()->getSchemaBuilder()->getColumnListing($table), true);
    }

    /**
     * Validate attribute input and return parsed fields.
     *
     * @param  array<int, array{code: string, values: mixed}>  $input
     * @return array<string, Field>
     *
     * @throws ValidationException|JsonException|BindingResolutionException
     */
    public function validate(array $input): array
    {
        return $this->validator()->validate($input);
    }

    /**
     * Get the attributes assigned to the entity.
     */
    public function assignedAttributes(): MorphToMany
    {
        return $this->morphToMany(Eav::$attributeModel, 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', ...array_column(AttributeStorage::cases(), 'value')]);
    }

    /**
     * Get the entity's stored attribute values.
     */
    public function attributeValues(): MorphMany
    {
        return $this->morphMany(Eav::$entityAttributeModel, 'entity')->chaperone('entity');
    }

    /**
     * Get attribute validator instance.
     */
    protected function validator(): AttributeValidator
    {
        return new AttributeValidator($this, $this->eav());
    }
}
