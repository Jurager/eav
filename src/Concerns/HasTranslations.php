<?php

declare(strict_types=1);

namespace Jurager\Eav\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Eav\Eav;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Scopes\ActiveLocaleScope;

trait HasTranslations
{
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
