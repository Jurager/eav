<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Eav;

/**
 * @property int $id
 * @property string $code
 * @property int $sort
 */
class AttributeGroup extends Model
{
    public $timestamps = false;

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
        return $this->morphToMany(Eav::$localeModel, 'entity', 'entity_translations')
            ->using(Eav::$entityTranslationModel)
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps()
            ->when(app(LocaleRegistry::class)->get(), fn ($q, $codes) => $q->whereIn('code', $codes));
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(Eav::$attributeModel, 'attribute_group_id');
    }
}
