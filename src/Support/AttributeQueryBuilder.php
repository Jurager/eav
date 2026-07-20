<?php

declare(strict_types=1);

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Filterable\Support\FilterOperator;

class AttributeQueryBuilder
{
    /**
     * @param \Closure(string): ?Field   $fieldResolver
     * @param \Closure(string): ?string  $entityTypeResolver
     */
    public function __construct(
        private readonly EnumRegistry $enumRegistry,
        private readonly \Closure $fieldResolver,
        private readonly \Closure $entityTypeResolver,
    ) {
    }

    /** Build entity attribute subquery. */
    public function subquery(string $code, mixed $value = null, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $field = ($this->fieldResolver)($code);
        $entityType = ($this->entityTypeResolver)($code);

        if (! $field || ! $entityType) {
            return null;
        }

        if ($field->isEnum()) {
            $value = $this->coerceEnum($field, $operator, $value);

            if ($this->isUnmatchableEnum($operator, $value)) {
                return null;
            }
        } else {
            $value = $this->coercePlain($field, $operator, $value);
        }

        $query = Eav::$entityAttributeModel::query()
            ->select('entity_id')
            ->where('entity_type', $entityType)
            ->where('attribute_id', $field->attribute()->id);

        $this->applyFieldCondition($query, $field, $operator, $value, $localeId);

        return $query;
    }

    /** Coerce value for enum fields. */
    private function coerceEnum(Field $field, string $operator, mixed $value): mixed
    {
        if (in_array($operator, ['null', 'not_null', 'like'], true)) {
            return $value;
        }

        $attrId = $field->attribute()->id;

        if (in_array($operator, ['in', 'nin'], true)) {
            return array_values(array_filter(
                array_map(fn ($v) => $this->enumRegistry->coerce($attrId, $v), (array) $value),
                fn ($v) => $v !== null,
            ));
        }

        return $this->enumRegistry->coerce($attrId, $value);
    }

    /** Check if enum condition is guaranteed to return no results. */
    private function isUnmatchableEnum(string $operator, mixed $value): bool
    {
        if (in_array($operator, ['in', 'nin'], true)) {
            return empty($value);
        }

        if (in_array($operator, ['null', 'not_null', 'like'], true)) {
            return false;
        }

        return $value === null;
    }

    /** Coerce value for plain fields. */
    private function coercePlain(Field $field, string $operator, mixed $value): mixed
    {
        if (in_array($operator, ['null', 'not_null'], true)) {
            return $value;
        }

        if (in_array($operator, ['in', 'nin'], true)) {
            return array_map(fn ($v) => $field->cast($v), (array) $value);
        }

        return $field->cast($value);
    }

    /** Apply condition to field column or translations. */
    private function applyFieldCondition(Builder $query, Field $field, string $operator, mixed $value, ?int $localeId): void
    {
        if (! $field->isLocalizable()) {
            $this->applyOperator($query, $field->column(), $operator, $value);

            return;
        }

        $query->whereHas('translations', function ($q) use ($operator, $value, $localeId): void {
            $this->applyOperator($q, 'entity_translations.label', $operator, $value);

            if ($localeId) {
                $q->where('entity_translations.locale_id', $localeId);
            }
        });
    }

    /** Return builder matching attribute condition. */
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

    /** Find entity by attribute value. */
    public function findBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Model
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->first();
    }

    /** Find all entities by attribute value. */
    public function findAllBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): Collection
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->get() ?? collect();
    }

    /** Find entities by attribute values. */
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

    /** Apply filter operator to query. */
    private function applyOperator(Builder $query, string $column, string $alias, mixed $value): void
    {
        if ($alias === 'like') {
            $this->applyLike($query, $column, $value);

            return;
        }

        match (FilterOperator::fromAlias($alias)) {
            FilterOperator::Eq         => $query->where($column, '=', $value),
            FilterOperator::Ne         => $query->where($column, '!=', $value),
            FilterOperator::Gt         => $query->where($column, '>', $value),
            FilterOperator::Gte        => $query->where($column, '>=', $value),
            FilterOperator::Lt         => $query->where($column, '<', $value),
            FilterOperator::Lte        => $query->where($column, '<=', $value),
            FilterOperator::In         => $query->whereIn($column, (array) $value),
            FilterOperator::Nin        => $query->whereNotIn($column, (array) $value),
            FilterOperator::IsNull     => $query->whereNull($column),
            FilterOperator::IsNotNull  => $query->whereNotNull($column),
            FilterOperator::Between    => $query->whereBetween($column, $value),
            FilterOperator::NotBetween => $query->whereNotBetween($column, $value),
            default                    => $query->where($column, $alias, $value),
        };
    }

    /** Apply like condition to query. */
    private function applyLike(Builder $query, string $column, mixed $value): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);

        $query->whereRaw($column . ' LIKE ?', ['%' . $escaped . '%']);
    }
}
