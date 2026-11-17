<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;
use Jurager\Eav\Search\Index\Synchronizer;

/**
 * Drops cached locales whenever a locale row changes, however it was written.
 */
class LocaleObserver
{
    public function __construct(
        protected LocaleRegistry $registry,
        protected Synchronizer $indexes,
    ) {}

    public function saved(Locale $locale): void
    {
        $this->registry->forget();

        if ($locale->wasRecentlyCreated || $locale->wasChanged('code')) {
            $this->indexes->locales();
        }
    }

    public function deleted(Locale $locale): void
    {
        $this->registry->forget();
        $this->indexes->locales();
    }
}
