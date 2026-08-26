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
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Support\AttributeValidator;

/**
 * @property Collection $assignedAttributes
 *
 * @phpstan-require-implements Attributable
 */
trait HasAttributes
{
    use HasClosureRelations;
    use HasAttributeScopes;
    use HasInheritedAttributes;

    /** Cached AttributeManager instance for this model. */
    protected ?AttributeManager $attributeManager = null;

    /** Boot trait attributes. */
    public static function bootHasAttributes(): void
    {
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

    /** Get cached attribute manager instance. */
    public function eav(): AttributeManager
    {
        return $this->attributeManager ??= AttributeManager::for($this);
    }

    /**
     * Get the entity type identifier — defaults to the model's Eloquent morph
     * class, so registering a `morphMap()` alias is enough. Override when you
     * need a value independent of the morph map.
     */
    public function getEntityType(): string
    {
        return $this->getMorphClass();
    }

    /** Fallback to EAV attribute reading for undefined properties. */
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

    /** Fallback to EAV attribute assignment for undefined properties. */
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

    /** Check if key is a real database column. */
    private function isRealColumn(string $key): bool
    {
        static $columns = [];

        $table = $this->getTable();

        return in_array($key, $columns[$table] ??= $this->getConnection()->getSchemaBuilder()->getColumnListing($table), true);
    }

    /**
     * Validate attribute input and return parsed fields.
     *
     * @param array<int, array{code: string, values: mixed}> $input
     * @return array<string, Field>
     *
     * @throws ValidationException|JsonException|BindingResolutionException
     */
    public function validate(array $input): array
    {
        return $this->validator()->validate($input);
    }

    /** Define Eloquent relation to Attribute through pivot. */
    public function assignedAttributes(): MorphToMany
    {
        return $this->morphToMany(Eav::$attributeModel, 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    /** Define Eloquent relation to entity_attribute values. */
    public function attributeValues(): MorphMany
    {
        return $this->morphMany(Eav::$entityAttributeModel, 'entity');
    }

    /** Get attribute validator instance. */
    protected function validator(): AttributeValidator
    {
        return new AttributeValidator($this, $this->attributeManager);
    }
}
