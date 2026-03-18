<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Jurager\Eav\Support\EavModels;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $entity_type
 * @property int $locale_id
 * @property string $label
 * @property array $params
 */
class EntityTranslation extends MorphPivot
{
    protected string $table = 'entity_translations';

    protected array $fillable = [
        'entity_id', 'entity_type', 'locale_id', 'label', 'params',
        'params->short_name', 'params->hint', 'params->placeholder',
    ];

    protected array $casts = [
        'params' => 'array',
    ];

    public function locale(): BelongsTo
    {
        return $this->belongsTo(EavModels::class('locale'), 'locale_id');
    }
}
