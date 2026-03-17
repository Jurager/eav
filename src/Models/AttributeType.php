<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Jurager\Eav\EavModels;

/**
 * @property int $id
 * @property string $code
 */
class AttributeType extends Model
{
    protected $fillable = ['code'];

    public function attributes(): HasMany
    {
        return $this->hasMany(EavModels::class('attribute'), 'attribute_type_id');
    }
}
