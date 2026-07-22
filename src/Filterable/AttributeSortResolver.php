<?php

declare(strict_types=1);

namespace Jurager\Eav\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Eav;
use Jurager\Eav\Managers\AttributeManager;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Filterable\Contracts\SortResolver;

class AttributeSortResolver implements SortResolver
{
    /** Resolve sort order for EAV attributes. */
    public function resolve(Builder $query, string $field, string $direction, Model $model): bool
    {
        if (str_contains($field, '.') || ! $model instanceof Attributable) {
            return false;
        }

        try {
            $entityType = $model->getEavEntityType();
            $eavField   = AttributeManager::for($entityType)->field($field);

            if (! $eavField) {
                return false;
            }

            $qualifiedKey = $model->qualifyColumn($model->getKeyName());

            $subquery = Eav::$entityAttributeModel::query()
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
                $subquery->select('_ea.' . $eavField->column());
            }

            $query->orderBy($subquery, $direction);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Resolve the current locale ID from registry. */
    private function resolveLocaleId(): ?int
    {
        try {
            $registry = app(LocaleRegistry::class);
            $codes    = $registry->get();

            return (! empty($codes)) ? $registry->find($codes[0]) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
