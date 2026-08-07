<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Enums\IndexCapability;
use Jurager\Eav\Eav;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Builder as SearchBuilder;
use Jurager\Filterable\Contracts\SortResolver;

class AttributeSortResolver implements SortResolver
{
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
        $eavField   = AttributeManager::for($entityType)->field($field);

        // Not an attribute of this entity — leave the field to the remaining resolvers.
        if (! $eavField) {
            return false;
        }

        $qualifiedKey = $model->qualifyColumn($model->getKeyName());
        $values       = new Eav::$entityAttributeModel();

        $subquery = $values->newQuery()
            ->from($values->getTable() . ' as _ea')
            ->whereColumn('_ea.entity_id', $qualifiedKey)
            ->where('_ea.entity_type', $entityType)
            ->where('_ea.attribute_id', $eavField->attribute()->id)
            ->orderBy('_ea.id')
            ->limit(1);

        if ($eavField->isLocalizable()) {
            $localeId     = $this->resolveLocaleId();
            $translations = new Eav::$entityTranslationModel();
            $valuesType   = $values->getMorphClass();

            $subquery
                ->join($translations->getTable() . ' as _et', function (JoinClause $join) use ($localeId, $valuesType): void {
                    $join->on('_et.entity_id', '=', '_ea.id')
                        ->where('_et.entity_type', '=', $valuesType);

                    if ($localeId !== null) {
                        $join->where('_et.locale_id', '=', $localeId);
                    }
                })
                ->orderBy('_et.locale_id')
                ->select('_et.label');
        } else {
            $subquery->select('_ea.' . $eavField->column()->value);
        }

        $query->orderBy($subquery, $direction);

        return true;
    }

    /**
     * Order search results by an indexed attribute.
     */
    private function orderIndex(SearchBuilder $query, string $field, string $direction, Model $model): bool
    {
        $path = 'attributes.' . $field;

        if (str_contains($field, '.') || ! IndexCapability::Sort->allowed($model, $path)) {
            return false;
        }

        $query->orderBy($path, $direction);

        return true;
    }

    /** Resolve the current locale ID from registry. */
    private function resolveLocaleId(): ?int
    {
        $registry = app(LocaleRegistry::class);
        $codes    = $registry->get();

        return empty($codes) ? null : $registry->find($codes[0]);
    }
}
