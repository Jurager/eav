<?php

namespace Jurager\Eav\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Support\AttributeInheritanceResolver;
use Jurager\Eav\Support\AttributeManager;
use Jurager\Eav\Support\AttributeValidator;
use Jurager\Eav\Support\EavModels;
use LogicException;

/**
 * Adds EAV attribute support to Eloquent models.
 *
 * Requires the model to implement Attributable.
 *
 * @property Collection $attribute_relation
 */
trait HasAttributes
{
    /**
     * Cached AttributeManager instance — one per model instance.
     */
    protected ?AttributeManager $attributeManager = null;

    /**
     * Return the AttributeManager for this entity (lazy-loaded, cached).
     */
    public function attributes(): AttributeManager
    {
        return $this->attributeManager ??= AttributeManager::for($this);
    }

    /**
     * Validate and fill attribute input, returning filled Field instances keyed by code.
     *
     * @param  array<int, array{code: string, values: mixed}>  $input
     * @return array<string, Field>
     *
     * @throws ValidationException|JsonException
     */
    public function validate(array $input): array
    {
        return (new AttributeValidator($this, $this->attributeManager))->validate($input);
    }

    /**
     * Return available attribute definitions for this entity.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     */
    public function getAvailableAttributes(array $params = []): Collection
    {
        $query = $this->getAvailableAttributesQuery($params);

        return $query ? $query->get() : collect();
    }

    /**
     * Return a query builder for available attributes (global or by relation).
     *
     * @param  array<string, mixed>  $params
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder
    {
        return match ($this->getAttributeScope()) {
            'byRelation' => $this->getAttributesByRelationQuery($params),
            default => $this->getGlobalAttributesQuery(),
        };
    }

    /**
     * Scope: filter by a single attribute value.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '='): Builder
    {
        $sub = $this->attributes()->subquery($code, $value, $operator);

        return $sub ? $query->whereIn('id', $sub) : $query;
    }

    /**
     * Scope: filter by a single attribute using LIKE search.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeLike(Builder $query, string $code, string $value): Builder
    {
        return $this->scopeWhereAttribute($query, $code, $value, 'like');
    }

    /**
     * Scope: filter by attribute value range (inclusive).
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeBetween(Builder $query, string $code, float|int $min, float|int $max): Builder
    {
        $sub = $this->attributes()->subquery($code, [$min, $max], 'between');

        return $sub ? $query->whereIn('id', $sub) : $query;
    }

    /**
     * Scope: filter by attribute value IN a set.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        $sub = $this->attributes()->subquery($code, $values, 'in');

        return $sub ? $query->whereIn('id', $sub) : $query;
    }

    /**
     * Scope: apply multiple attribute conditions (AND logic).
     *
     * Each condition: ['code' => string, 'value' => mixed, 'operator' => string (optional)].
     *
     * @param  array<int, array{code: string, value: mixed, operator?: string}>  $conditions
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributes(Builder $query, array $conditions): Builder
    {
        $manager = $this->attributes();

        foreach ($conditions as $condition) {
            $sub = $manager->subquery(
                $condition['code'],
                $condition['value'],
                $condition['operator'] ?? '=',
            );

            if ($sub) {
                $query->whereIn('id', $sub);
            }
        }

        return $query;
    }

    /**
     * Whether this entity should inherit attributes from its parent.
     * Override in models that support attribute inheritance.
     */
    public function shouldInheritAttributes(): bool
    {
        return false;
    }

    /**
     * Default filter parameters passed to getAvailableAttributesQuery().
     * Override in models that use byRelation scope (e.g. return category IDs for Product).
     */
    public function getDefaultParameters(): array
    {
        return [];
    }

    /**
     * Relation that provides available attributes for other entities scoped by this model.
     * Override in models that act as attribute scope providers (e.g. Category for Product).
     */
    public function available_attributes(): ?BelongsToMany
    {
        return null;
    }

    /**
     * Raw Eloquent relation to Attribute through entity_attribute pivot (with value columns).
     */
    public function attribute_relation(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('attribute'), 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    /**
     * Raw Eloquent relation to entity_attribute rows for this entity.
     */
    public function attribute_values(): HasMany
    {
        return $this->hasMany(EavModels::class('entity_attribute'), 'entity_id')
            ->where('entity_type', $this->getAttributeEntityType());
    }

    /**
     * Return the attribute scope strategy for this entity.
     * Override in models that scope attributes by a related model.
     */
    protected function getAttributeScope(): string
    {
        return 'global';
    }

    /**
     * Return the FQCN of the model used to resolve relation-scoped attributes.
     * Must be overridden when getAttributeScope() returns 'byRelation'.
     *
     * @return class-string
     */
    protected static function getAttributeRelationModel(): string
    {
        throw new LogicException(
            static::class.' must implement getAttributeRelationModel() when getAttributeScope() returns "byRelation".'
        );
    }

    /**
     * Return a query for all attributes shared globally for this entity type.
     */
    protected function getGlobalAttributesQuery(): Builder
    {
        return EavModels::query('attribute')
            ->forEntity($this->getAttributeEntityType())
            ->withRelations();
    }

    /**
     * Return a query for attributes scoped through related entities (e.g. categories for products).
     * Returns null when params are empty or the relation model cannot be resolved.
     *
     * @param  array<string, mixed>  $params  IDs of the related entities.
     */
    protected function getAttributesByRelationQuery(array $params = []): ?Builder
    {
        if (empty($params)) {
            return null;
        }

        $model = static::getAttributeRelationModel();

        if (! is_subclass_of($model, Attributable::class)) {
            return null;
        }

        $entities = $model::query()
            ->whereIn('id', $params)
            ->select(['id', '_lft', '_rgt', 'parent_id', 'is_inherits_properties'])
            ->get()
            ->keyBy('id');

        if ($entities->isEmpty()) {
            return null;
        }

        $allEntities = app(AttributeInheritanceResolver::class)->resolve($entities, $model);

        $relation = (new $model())->available_attributes();

        if ($relation === null) {
            return null;
        }

        $pivotTable = $relation->getTable();
        $foreignKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();

        return EavModels::query('attribute')
            ->whereIn('id', fn ($q) => $q->select($relatedKey)->from($pivotTable)->whereIn($foreignKey, $allEntities->pluck('id')))
            ->withRelations();
    }
}
