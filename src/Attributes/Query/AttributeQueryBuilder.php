<?php

declare(strict_types=1);

namespace Jurager\Eav\Attributes\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Jurager\Eav\Eav;
use Jurager\Eav\Fields\Field;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Filterable\Support\FilterOperator;

/**
 * Build queries that match entities by attribute value.
 */
class AttributeQueryBuilder
{
    /**
     * @param  class-string<Model>|null  $model  Entity class the lookups return; the type's morph-map entry when null.
     */
    public function __construct(
        private readonly EnumRegistry $enumRegistry,
        private readonly FieldFactory $fields,
        private readonly AttributeRegistry $attributes,
        private readonly string $entityType,
        private readonly ?string $model = null,
    ) {}

    /**
     * Build a matching entity ID query, or null when no match is possible.
     */
    public function subquery(string $code, mixed $value = null, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $field = $this->field($code);

        if ($field === null) {
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
            ->where('entity_type', $this->entityType)
            ->where('attribute_id', $field->attribute()->id);

        $this->applyFieldCondition($query, $field, $operator, $value, $localeId);

        return $query;
    }

    /**
     * The field of an attribute of this entity type, or null when there is none.
     */
    private function field(string $code): ?Field
    {
        $attribute = $this->attributes->forCodes($this->entityType, [$code])->first();

        return $attribute !== null ? $this->fields->make($attribute) : null;
    }

    /** @return class-string<Model>|null */
    private function model(): ?string
    {
        return $this->model ?? Relation::getMorphedModel($this->entityType);
    }

    /**
     * Coerce value for enum fields.
     */
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

    /**
     * Check if enum condition is guaranteed to return no results.
     */
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

    /**
     * Coerce value for plain fields.
     */
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

    /**
     * Apply condition to field column or translations.
     */
    private function applyFieldCondition(Builder $query, Field $field, string $operator, mixed $value, ?int $localeId): void
    {
        if (! $field->isLocalizable()) {
            $this->applyOperator($query, $field->column()->value, $operator, $value);

            return;
        }

        $query->whereHas('translations', function ($q) use ($operator, $value, $localeId): void {
            $this->applyOperator($q, 'entity_translations.label', $operator, $value);

            if ($localeId) {
                $q->where('entity_translations.locale_id', $localeId);
            }
        });
    }

    /**
     * Query of the entities whose attribute matches, or null when nothing can match.
     */
    public function attributeQuery(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Builder
    {
        $model = $this->model();
        $sub = $model !== null ? $this->subquery($code, $value, $operator, $localeId) : null;

        if ($sub === null) {
            return null;
        }

        $query = $model::query();

        return $query->whereIn($query->getModel()->getQualifiedKeyName(), $sub);
    }

    /**
     * The first entity whose attribute matches.
     */
    public function findBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): ?Model
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->first();
    }

    /**
     * Every entity whose attribute matches.
     */
    public function findAllBy(string $code, mixed $value, string $operator = '=', ?int $localeId = null): Collection
    {
        return $this->attributeQuery($code, $value, $operator, $localeId)?->get() ?? collect();
    }

    /**
     * Get matching entities keyed by their stored attribute value.
     *
     * @param  list<mixed>  $values
     * @return Collection<string, Model>
     */
    public function findWhereIn(string $code, array $values): Collection
    {
        $field = $this->field($code);
        $model = $this->model();

        if ($field === null || $model === null) {
            return collect();
        }

        $column = $field->column()->value;

        $rows = Eav::$entityAttributeModel::query()
            ->select(['entity_id', $column])
            ->where('entity_type', $this->entityType)
            ->where('attribute_id', $field->attribute()->id)
            ->whereIn($column, $values)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $entities = $model::query()->findMany($rows->pluck('entity_id')->unique()->values())->keyBy(static fn (Model $entity) => $entity->getKey());

        return $rows->mapWithKeys(static function ($row) use ($entities, $column): array {
            $entity = $entities[$row->entity_id] ?? null;

            return $entity !== null ? [(string) $row->{$column} => $entity] : [];
        });
    }

    /**
     * Apply filter operator to query.
     */
    private function applyOperator(Builder $query, string $column, string $alias, mixed $value): void
    {
        if ($alias === 'like') {
            $this->applyLike($query, $column, $value);

            return;
        }

        match (FilterOperator::fromAlias($alias)) {
            FilterOperator::Eq => $query->where($column, '=', $value),
            FilterOperator::Ne => $query->where($column, '!=', $value),
            FilterOperator::Gt => $query->where($column, '>', $value),
            FilterOperator::Gte => $query->where($column, '>=', $value),
            FilterOperator::Lt => $query->where($column, '<', $value),
            FilterOperator::Lte => $query->where($column, '<=', $value),
            FilterOperator::In => $query->whereIn($column, (array) $value),
            FilterOperator::Nin => $query->whereNotIn($column, (array) $value),
            FilterOperator::IsNull => $query->whereNull($column),
            FilterOperator::IsNotNull => $query->whereNotNull($column),
            FilterOperator::Between => $query->whereBetween($column, $value),
            FilterOperator::NotBetween => $query->whereNotBetween($column, $value),
            default => $query->where($column, $alias, $value),
        };
    }

    /**
     * Match a case-insensitive substring, treating % and _ literally.
     */
    private function applyLike(Builder $query, string $column, mixed $value): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value).'%';
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $query->getConnection()->getDriverName() === 'pgsql'
            ? $query->whereRaw("$wrapped::text ILIKE ? ESCAPE '!'", [$pattern])
            : $query->whereRaw("$wrapped LIKE ? ESCAPE '!'", [$pattern]);
    }
}
