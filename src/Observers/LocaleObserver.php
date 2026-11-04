<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\Locale;
use Jurager\Eav\Registry\LocaleRegistry;

/**
 * Drops cached locales whenever a locale row changes, however it was written.
 */
class LocaleObserver
{
    public function __construct(
        protected LocaleRegistry $registry,
    ) {}

    public function saved(Locale $locale): void
    {
        $this->registry->forget();
    }

    public function deleted(Locale $locale): void
    {
        $this->registry->forget();
    }
}
