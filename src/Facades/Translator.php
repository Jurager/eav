<?php

declare(strict_types=1);

namespace Jurager\Eav\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Jurager\Eav\Builders\Translator\LabelBuilder;
use Jurager\Eav\Builders\Translator\LocaleBuilder;
use Jurager\Eav\Builders\Translator\TranslatorFactory;
use Jurager\Eav\Models\Locale;

/**
 * Fluent builder for locales and for the translated labels of any model.
 *
 * Pass a code to build a new locale, or an existing model to build an update:
 *
 *     Translator::locale('de')->name('German')->create();
 *     Translator::locale($locale)->name('Deutsch')->update();
 *
 * A translation never exists on its own — it always belongs to a model.
 * `for()` starts a builder scoped to that model:
 *
 *     Translator::for($attribute)->label('Color', 'en')->label('Цвет', 'ru')->save();
 *
 * For bulk imports, collect builders without persisting them and flush them
 * together:
 *
 *     Translator::batch([
 *         Translator::for($attribute1)->label('Color', 'en'),
 *         Translator::for($attribute2)->label('Size', 'en'),
 *     ]);
 *
 * @method static LocaleBuilder locale(Locale|string $code)
 * @method static LabelBuilder for(Model $model)
 * @method static void batch(array $builders, ?Carbon $timestamp = null)
 * @method static Locale findLocale(int $id)
 * @method static mixed locales(?callable $modifier = null)
 *
 * @see TranslatorFactory
 */
class Translator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TranslatorFactory::class;
    }
}
