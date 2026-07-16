<?php

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Registry\EnumRegistry;

class AttributeQueryBuilder
{
    /**
     * @param  \Closure(string): ?Field   $fieldResolver
     * @param  \Closure(string): ?string  $entityTypeResolver
     */
    public function __construct(
        private readonly EnumRegistry $enumRegistry,
        private readonly \Closure $fieldResolver,
        private readonly \Closure $entityTypeResolver,
    ) {
    }

    /**
     * Build a subquery on entity_attribute selecting entity_id rows matching the given condition.
     */
    public function subquery(string $code, mixed $value = null, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $field = ($this->fieldResolver)($code);
        $entityType = ($this->entityTypeResolver)($code);

        if (! $field || ! $entityType) {
            return null;
        }

        $sub = Eav::$entityAttributeModel::query()
            ->select('entity_id')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id);

        if ($field->isEnum()) {
            $attrId = $field->attribute()->id;

            if (in_array($operator, ['in', 'nin'], true)) {
                $value = array_values(array_filter(
                    array_map(fn ($v) => $this->enumRegistry->coerce($attrId, $v), (array) $value),
                    fn ($v) => $v !== null,
                ));
            } elseif (! in_array($operator, ['null', 'not_null', 'like'], true)) {
                $value = $this->enumRegistry->coerce($attrId, $value);

                if ($value === null) {
                    return $sub->whereRaw('1 = 0');
                }
            }
        } elseif (! in_array($operator, ['null', 'not_null'], true)) {
            $value = in_array($operator, ['in', 'nin'], true)
                ? array_map(fn ($v) => $field->cast($v), (array) $value)
                : $field->cast($value);
        }

        if ($field->isLocalizable()) {
            $sub->whereHas('translations', function ($q) use ($value, $operator, $localeId) {
                $this->applyOperator($q, 'entity_translations.label', $operator, $value);

                if ($localeId) {
                    $q->where('entity_translations.locale_id', $localeId);
                }
            });
        } else {
            $this->applyOperator($sub, $field->column(), $operator, $value);
        }

        return $sub;
    }

    /**
     * Return a Builder scoped to entities whose attribute matches the given condition.
     */
    public function attributeQuery(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $entityType = ($this->entityTypeResolver)($code);
        $modelClass = $entityType ? Relation::getMorphedModel($entityType) : null;
        $sub = $this->subquery($code, $value, $operator, $localeId);

        if (! $sub || ! $modelClass) {
            return null;
        }

        return $modelClass::query()->whereIn('id', $sub);
    }

    /**
     * Find a single entity by attribute value.
     */
    public function findBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Model
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->first();
    }

    /**
     * Find all entities by attribute value.
     *
     * @return Collection<int, Model>
     */
    public function findAllBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): Collection
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->get() ?? collect();
    }

    /**
     * Find all entities whose attribute value is in the given list.
     * Returns a Collection keyed by the raw attribute value for O(1) lookup.
     *
     * @param  array<int|string>  $values
     * @return Collection<string, Model>
     */
    public function findWhereIn(string $code, array $values): Collection
    {
        $field = ($this->fieldResolver)($code);
        $entityType = ($this->entityTypeResolver)($code);
        $modelClass = $entityType ? Relation::getMorphedModel($entityType) : null;

        if (! $field || ! $entityType || ! $modelClass) {
            return collect();
        }

        $column = $field->column();

        $rows = Eav::$entityAttributeModel::query()
            ->select(['entity_id', $column])
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->whereIn($column, $values)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $models = $modelClass::query()
            ->whereIn('id', $rows->pluck('entity_id'))
            ->get()
            ->keyBy('id');

        return $rows->mapWithKeys(function ($row) use ($models, $column): array {
            $model = $models[$row->entity_id] ?? null;

            return $model ? [(string) $row->{$column} => $model] : [];
        });
    }

    private function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'like' => $this->applyLike($query, $column, $value),
            '=', 'eq' => $query->where($column, '=', $value),
            '!=', 'ne' => $query->where($column, '!=', $value),
            'in' => $query->whereIn($column, (array) $value),
            'nin', 'not_in' => $query->whereNotIn($column, (array) $value),
            'null' => $query->whereNull($column),
            'not_null' => $query->whereNotNull($column),
            'between' => $query->whereBetween($column, $value),
            'not_between' => $query->whereNotBetween($column, $value),
            default => $query->where($column, $operator, $value),
        };
    }

    private function applyLike(Builder $query, string $column, mixed $value): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);

        $query->whereRaw($column.' LIKE ?', ['%'.$escaped.'%']);
    }
}
