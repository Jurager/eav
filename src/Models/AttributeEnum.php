<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\EavModels;

/**
 * @property int    $id
 * @property int    $attribute_id
 * @property string $code
 * @property int    $sort
 */
class AttributeEnum extends Model
{
    protected $fillable = ['attribute_id', 'code', 'sort'];

    public function translations(): MorphToMany
    {
        return $this->morphToMany(EavModels::class('locale'), 'entity', 'entity_translations')
            ->using(EavModels::class('entity_translation'))
            ->withPivot(['id', 'label', 'params', 'updated_at']);
    }
}
