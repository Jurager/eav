<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Eav;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $code
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Attribute|null $attribute
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Locale> $translations
 */
class AttributeEnum extends Model
{
    protected $fillable = ['attribute_id', 'code', 'sort'];

    protected static function booted(): void
    {
        static::deleting(static function (AttributeEnum $enum) {
            $enum->translations()->delete();
        });

        static::addGlobalScope('ordered', static function (Builder $query) {
            $query->orderBy('sort')->orderBy('id');
        });
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(Eav::$localeModel, 'entity', 'entity_translations')
            ->using(Eav::$entityTranslationModel)
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps()
            ->when(app(LocaleRegistry::class)->get(), fn ($q, $codes) => $q->whereIn('code', $codes));
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Eav::$attributeModel, 'attribute_id');
    }

    public function label(int $localeId): ?string
    {
        return $this->translations
            ->first(fn ($t) => $t->pivot->getAttribute('locale_id') === $localeId)
            ?->pivot
            ?->label;
    }

    /**
     * Restrict enum options to those actually selected by the given entities.
     *
     * @param  Builder  $entities  Entity query, kept as a subquery so ids are never materialised.
     */
    public function scopeUsedBy(Builder $query, Builder $entities): Builder
    {
        $enum       = $query->getModel();
        $valuesClass = Eav::$entityAttributeModel;
        $values      = new $valuesClass();

        return $query->whereExists(
            fn ($sub) => $sub
                ->from($values->getTable())
                ->whereColumn($values->qualifyColumn('value_integer'), $enum->getQualifiedKeyName())
                ->whereColumn($values->qualifyColumn('attribute_id'), $enum->qualifyColumn('attribute_id'))
                ->where($values->qualifyColumn('entity_type'), $entities->getModel()->getMorphClass())
                ->whereIn(
                    $values->qualifyColumn('entity_id'),
                    // Cloned because the select would otherwise narrow the caller's own query
                    $entities->clone()->select($entities->getModel()->getQualifiedKeyName())
                )
        );
    }
}
