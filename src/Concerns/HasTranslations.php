<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Eav;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Scopes\ActiveLocaleScope;

trait HasTranslations
{
    /**
     * Remove the labels together with the model, or with its force delete when it soft deletes.
     */
    public static function bootHasTranslations(): void
    {
        $event = in_array(SoftDeletes::class, class_uses_recursive(static::class), true) ? 'forceDeleting' : 'deleting';

        static::registerModelEvent($event, static fn (Model $model) => $model->translations()->detach());
    }

    public function translations(): MorphToMany
    {
        return $this->morphToMany(Eav::$localeModel, 'entity', 'entity_translations')
            ->using(Eav::$entityTranslationModel)
            ->withPivot(['id', 'label', 'params'])
            ->withTimestamps()
            ->withoutGlobalScope(ActiveLocaleScope::class);
    }

    public function label(?int $localeId = null): ?string
    {
        $localeId ??= app(LocaleRegistry::class)->current();

        $translations = $this->translations;

        $translation = $translations->first(fn ($t) => (int) $t->pivot->getAttribute('locale_id') === $localeId) ?? $translations->first();

        return $translation?->pivot?->getAttribute('label');
    }
}
