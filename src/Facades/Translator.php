<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Models\Locale;
use Jurager\Eav\Translation\LabelBuilder;
use Jurager\Eav\Translation\LocaleBuilder;
use Jurager\Eav\Translation\Translator as Service;

/**
 * Manage locales and translated model labels.
 *
 * @method static LocaleBuilder locale(Locale|string $code)
 * @method static LabelBuilder for(Model $model)
 * @method static void batch(array $builders, ?Carbon $timestamp = null)
 * @method static Locale findLocale(int $id)
 * @method static mixed locales(?callable $modifier = null)
 *
 * @see Service
 */
class Translator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Service::class;
    }
}
