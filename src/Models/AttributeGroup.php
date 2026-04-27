<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Support\EavModels;

/**
 * @property int $id
 * @property string $code
 * @property int $sort
 */
class AttributeGroup extends Model
{
    protected $fillable = ['code', 'sort'];

    protected static function booted(): void
    {
        static::deleting(static function (AttributeGroup $group) {
            $group->translations()->delete();
        });

        static::addGlobalScope('ordered', static function (Builder $query) {
            $query->orderBy('sort')->orderBy('id');
        });
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('locale'), 'entity', 'entity_translations')
            ->using(EavModels::class('entity_translation'))
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps()
            ->when(app(LocaleRegistry::class)->get(), fn ($q, $codes) => $q->whereIn('code', $codes));
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(EavModels::class('attribute'), 'attribute_group_id');
    }
}
