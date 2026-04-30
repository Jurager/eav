<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Locale extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'name'];
}
