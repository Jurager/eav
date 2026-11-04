<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Jurager\Eav\Scopes\ActiveLocaleScope;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Locale extends Model
{
    protected $fillable = ['code', 'name'];

    protected static function booted(): void
    {
        static::addGlobalScope(new ActiveLocaleScope);
    }
}
