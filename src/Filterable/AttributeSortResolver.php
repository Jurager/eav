<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Enums\IndexCapability;
use Jurager\Eav\Fields\FieldFactory;
use Jurager\Eav\Registry\AttributeRegistry;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Builder as SearchBuilder;
use Jurager\Filterable\Contracts\SortResolver;

class AttributeSortResolver implements SortResolver
{
    public function __construct(
        private readonly AttributeRegistry $attributes,
        private readonly FieldFactory $fields,
        private readonly LocaleRegistry $locales,
    ) {}

    /** Resolve sort order for EAV attributes, in the database or in the search index. */
    public function resolve(object $query, string $field, string $direction, Model $model, array $context = []): bool
    {
        if ($query instanceof SearchBuilder) {
            return $this->orderIndex($query, $field, $direction, $model);
        }

        if (! $query instanceof Builder || str_contains($field, '.') || ! $model instanceof Attributable) {
            return false;
        }

        $entityType = $model->getEntityType();
        $attribute = $this->attributes->forCodes($entityType, [$field])->first();

        // Not an attribute of this entity — leave the field to the remaining resolvers.
        if ($attribute === null) {
            return false;
        }

        $eavField = $this->fields->make($attribute);

        $qualifiedKey = $model->qualifyColumn($model->getKeyName());
        $valuesClass = Eav::$entityAttributeModel;
        $values = new $valuesClass;

        $subquery = $values->newQuery()
            ->from($values->getTable().' as _ea')
            ->whereColumn('_ea.entity_id', $qualifiedKey)
            ->where('_ea.entity_type', $entityType)
            ->where('_ea.attribute_id', $eavField->attribute()->getAttribute('id'))
            ->orderBy('_ea.id')
            ->limit(1);

        if ($eavField->isLocalizable()) {
            $localeId = $this->resolveLocaleId();
            $translationsClass = Eav::$entityTranslationModel;
            $translations = new $translationsClass;
            $valuesType = $values->getMorphClass();

            $subquery
                ->join($translations->getTable().' as _et', function (JoinClause $join) use ($localeId, $valuesType): void {
                    $join->on('_et.entity_id', '=', '_ea.id')
                        ->where('_et.entity_type', '=', $valuesType);

                    if ($localeId !== null) {
                        $join->where('_et.locale_id', '=', $localeId);
                    }
                })
                ->orderBy('_et.locale_id')
                ->select('_et.label');
        } else {
            $subquery->select('_ea.'.$eavField->column()->value);
        }

        $query->orderBy($subquery, $direction);

        return true;
    }

    /** Order search results by an indexed attribute. */
    private function orderIndex(SearchBuilder $query, string $field, string $direction, Model $model): bool
    {
        $path = 'attributes.'.$field;

        if (str_contains($field, '.') || ! IndexCapability::Sort->allowed($model, $path)) {
            return false;
        }

        $query->orderBy($path, $direction);

        return true;
    }

    /** Resolve the current locale ID from registry. */
    private function resolveLocaleId(): ?int
    {
        $codes = $this->locales->get();

        return empty($codes) ? null : $this->locales->find($codes[0]);
    }
}
