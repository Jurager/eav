<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jurager\Eav\Eav;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $entity_type
 * @property-read Attribute|null $attribute
 */
class AttributeEntityType extends Model
{
    protected $fillable = ['attribute_id', 'entity_type'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Eav::$attributeModel);
    }
}
