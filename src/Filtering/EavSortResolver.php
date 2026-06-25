<?php

namespace Jurager\Eav\Filtering;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\EavModels;
use Jurager\Filterable\Contracts\SortResolverInterface;

/**
 * Handles sort fields not listed in $sortable by treating them as EAV attribute codes.
 *
 * Builds a correlated ORDER BY subquery against entity_attribute.
 * Localizable attributes are sorted by translated label for the current locale
 * (resolved from Accept-Language via LocaleRegistry).
 *
 * Registered in EavServiceProvider and injected into every Filterable instance
 * via HasFilterable::newFilterable().
 */
class EavSortResolver implements SortResolverInterface
{
    public function resolve(Builder $query, string $field, string $direction, Model $model): bool
    {
        if (str_contains($field, '.') || !method_exists($model, 'attributeEntityType')) {
            return false;
        }

        try {
            $entityType = $model->attributeEntityType();
            $eavField   = AttributeManager::for($entityType)->field($field);

            if (!$eavField) {
                return false;
            }

            $qualifiedKey = $model->qualifyColumn($model->getKeyName());

            $subquery = EavModels::query('entity_attribute')
                ->from('entity_attribute as _ea')
                ->whereColumn('_ea.entity_id', $qualifiedKey)
                ->where('_ea.entity_type', $entityType)
                ->where('_ea.attribute_id', $eavField->attribute()->id)
                ->orderBy('_ea.id')
                ->limit(1);

            if ($eavField->isLocalizable()) {
                $localeId = $this->resolveLocaleId();

                $subquery
                    ->join('entity_translations as _et', function (JoinClause $join) use ($localeId): void {
                        $join->on('_et.entity_id', '=', '_ea.id')
                            ->where('_et.entity_type', '=', 'entity_attribute');

                        if ($localeId !== null) {
                            $join->where('_et.locale_id', '=', $localeId);
                        }
                    })
                    ->orderBy('_et.locale_id')
                    ->select('_et.label');
            } else {
                $subquery->select('_ea.'.$eavField->column());
            }

            $query->orderBy($subquery, $direction);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the locale ID from Accept-Language via LocaleRegistry.
     *
     * Override in a subclass to supply a locale through a different mechanism.
     */
    protected function resolveLocaleId(): ?int
    {
        try {
            $registry = app(LocaleRegistry::class);
            $codes    = $registry->get();

            return $codes ? $registry->find($codes[0]) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
