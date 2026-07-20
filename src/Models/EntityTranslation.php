<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Jurager\Eav\Eav;

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
    public $incrementing = true;

    protected $table = 'entity_translations';

    protected $fillable = [
        'entity_id', 'entity_type', 'locale_id', 'label', 'params',
    ];

    public function locale(): BelongsTo
    {
        return $this->belongsTo(Eav::$localeModel, 'locale_id');
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }
}
