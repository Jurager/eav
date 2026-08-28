<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Scopes\ActiveLocaleScope;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Locale extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'name'];

    protected static function booted(): void
    {
        static::addGlobalScope(new ActiveLocaleScope());
    }
}
