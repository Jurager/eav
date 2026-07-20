<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Locale extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'name'];

    /** Scope: restrict to active locales when set by the current request context. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->when(app(LocaleRegistry::class)->get(), fn ($q, $codes) => $q->whereIn('code', $codes));
    }
}
