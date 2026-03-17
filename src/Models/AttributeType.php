<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $code
 */
class AttributeType extends Model
{
    protected $fillable = ['code'];
}
