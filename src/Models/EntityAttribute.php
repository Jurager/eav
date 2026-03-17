<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\EavModels;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $entity_type
 * @property int $attribute_id
 * @property string|null $value_text
 * @property int|null $value_integer
 * @property float|null $value_float
 * @property bool|null $value_boolean
 * @property string|null $value_date
 * @property string|null $value_datetime
 */
class EntityAttribute extends Model
{
    protected $table = 'entity_attribute';

    protected $fillable = [
        'entity_id', 'entity_type', 'attribute_id',
        'value_text', 'value_integer', 'value_float',
        'value_boolean', 'value_date', 'value_datetime',
    ];

    protected $casts = [
        'value_boolean'  => 'boolean',
        'value_date'     => 'date',
        'value_datetime' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(static function (EntityAttribute $entityAttribute) {
            $entityAttribute->translations()->delete();
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(EavModels::class('attribute'));
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('locale'), 'entity', 'entity_translations')
            ->using(EavModels::class('entity_translation'))
            ->withPivot(['id', 'label', 'created_at', 'updated_at']);
    }
}
