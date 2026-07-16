<?php

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
            ->first(fn ($t) => $t->pivot->locale_id === $localeId)
            ?->pivot
            ?->label;
    }
}
