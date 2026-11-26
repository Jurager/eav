<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Eav;
use Jurager\Eav\Registries\LocaleRegistry;

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
            ->withTimestamps();
    }

    public function label(?int $localeId = null): ?string
    {
        $localeId ??= app(LocaleRegistry::class)->current();

        return $this->translations
            ->first(fn ($t) => (int) $t->pivot->getAttribute('locale_id') === $localeId)
            ?->pivot
            ?->getAttribute('label');
    }
}
