<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registries\LocaleRegistry;
use Jurager\Eav\Search\Index\Synchronizer;

/**
 * Drops cached locales and brings search indexes in step whenever a locale row changes, however it was written.
 */
class LocaleObserver
{
    public function __construct(
        protected LocaleRegistry $registry,
        protected Synchronizer $indexes,
    ) {}

    public function created(Locale $locale): void
    {
        $this->registry->forget();
        $this->indexes->locales();
    }

    public function updated(Locale $locale): void
    {
        $this->registry->forget();

        // Documents key localized values by locale code: a renamed code leaves them under the old one.
        if ($locale->wasChanged('code')) {
            $this->indexes->locales(documents: true);
        }
    }

    public function deleted(Locale $locale): void
    {
        $this->registry->forget();
        $this->indexes->locales(documents: true);
    }
}
