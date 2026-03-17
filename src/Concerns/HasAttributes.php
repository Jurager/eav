<?php

namespace Jurager\Eav\Concerns;

use Jurager\Eav\AttributeInheritanceResolver;
use Jurager\Eav\AttributeManager;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\EavModels;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use JsonException;

/**
 * Adds dynamic attribute support to Eloquent models.
 *
 * Requires the model to implement Attributable.
 *
 * @property Collection $attributeRelation
 */
trait HasAttributes
{
    /**
     * Cached attribute manager instance. One manager per model instance.
     */
    protected ?AttributeManager $attributeManager = null;

    /**
     * Return the cached AttributeManager for this entity.
     */
    public function attributes(): AttributeManager
    {
        return $this->attributeManager ??= AttributeManager::for($this);
    }

    /**
     * Get available attribute definitions for this entity.
     *
     * @param  array<string, mixed> $params
     * @return Collection<int, mixed>
     */
    public function getAvailableAttributes(array $params = []): Collection
    {
        $query = $this->getAvailableAttributesQuery($params);

        return $query ? $query->get() : collect();
    }

    /**
     * Get query builder for available attributes (global or by relation).
     *
     * @param  array<string, mixed> $params
     */
    public function getAvailableAttributesQuery(array $params = []): ?Builder
    {
        return match ($this->getAttributeScope()) {
            'byRelation' => $this->getAttributesByRelationQuery($params),
            default      => $this->getGlobalAttributesQuery(),
        };
    }

    /**
     * Scope: filter by a single attribute value.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttribute(Builder $query, string $code, mixed $value, string $operator = '='): Builder
    {
        $sub = $this->attributes()->buildSubquery($code, $value, $operator);

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
        $sub = $this->attributes()->buildSubquery($code, [$min, $max], 'between');

        return $sub ? $query->whereIn('id', $sub) : $query;
    }

    /**
     * Scope: filter by attribute value IN a set.
     *
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributeIn(Builder $query, string $code, array $values): Builder
    {
        $sub = $this->attributes()->buildSubquery($code, $values, 'in');

        return $sub ? $query->whereIn('id', $sub) : $query;
    }

    /**
     * Scope: apply multiple attribute conditions (AND logic).
     *
     * Each condition: ['code' => string, 'value' => mixed, 'operator' => string (optional)].
     *
     * @param  array<int, array{code: string, value: mixed, operator?: string}> $conditions
     * @throws JsonException|BindingResolutionException
     */
    public function scopeWhereAttributes(Builder $query, array $conditions): Builder
    {
        $manager = $this->attributes();

        foreach ($conditions as $condition) {
            $sub = $manager->buildSubquery(
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
     * Raw Eloquent relation to Attribute through entity_attribute pivot.
     */
    public function attributeRelation(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('attribute'), 'entity', 'entity_attribute')
            ->withTimestamps()
            ->withPivot(['id', 'value_text', 'value_integer', 'value_float', 'value_boolean', 'value_date', 'value_datetime']);
    }

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    /**
     * Return the attribute scope strategy for this entity.
     * Override in models that scope attributes by a relation (e.g. category).
     */
    protected function getAttributeScope(): string
    {
        return 'global';
    }

    /**
     * Get all attributes shared globally for this entity type.
     */
    protected function getGlobalAttributesQuery(): Builder
    {
        return EavModels::query('attribute')
            ->forEntity($this->getAttributeEntityType())
            ->withRelations();
    }

    /**
     * Build the attribute query scoped by related entities (e.g. categories for a product).
     * Returns null when params are empty or the relation model is invalid.
     *
     * @param  array<string, mixed> $params
     */
    protected function getAttributesByRelationQuery(array $params = []): ?Builder
    {
        if (empty($params)) {
            return null;
        }

        $model = static::getAttributeRelationModel();

        if (!is_subclass_of($model, Attributable::class)) {
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

        $relation   = 'available' . ucfirst($this->getAttributeEntityType()) . 'Attributes';
        $instance   = new $model()->{$relation}();
        $pivotTable = $instance->getTable();
        $foreignKey = $instance->getForeignPivotKeyName();
        $relatedKey = $instance->getRelatedPivotKeyName();

        return EavModels::query('attribute')
            ->whereIn('id', fn ($q) => $q->select($relatedKey)->from($pivotTable)->whereIn($foreignKey, $allEntities->pluck('id')))
            ->with(['type', 'group.translations', 'measurement.translations', 'measurement.units', 'translations']);
    }
}
