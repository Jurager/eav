<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry\Concerns;

use Closure;

/** Detect whether a table (or one scope of it) moved since a registry last read it, checked at most once per request. */
trait TracksTableChanges
{
    /** @var array<string, string> */
    private static array $trackedStamps = [];

    /** @var array<string, true> */
    private array $trackedChecked = [];

    /** Determine whether a scope changed since its stamp was last recorded, refreshing that stamp whenever a real check runs. */
    private function tableChanged(Closure $stamp, string $scope = ''): bool
    {
        if (isset($this->trackedChecked[$scope])) {
            return false;
        }

        $value = $stamp();
        $changed = $value !== (self::$trackedStamps[$scope] ?? null);

        $this->trackedChecked[$scope] = true;
        self::$trackedStamps[$scope] = $value;

        return $changed;
    }

    /** Record a scope's current stamp without comparing — call right after a full (re)load. */
    private function markTableFresh(Closure $stamp, string $scope = ''): void
    {
        $this->trackedChecked[$scope] = true;
        self::$trackedStamps[$scope] = $stamp();
    }

    /** Drop the recorded stamp for one scope, or every scope. */
    private function forgetTableChange(?string $scope = null): void
    {
        if ($scope === null) {
            $this->trackedChecked = [];
            self::$trackedStamps = [];

            return;
        }

        unset(self::$trackedStamps[$scope], $this->trackedChecked[$scope]);
    }

    /** Drop every recorded stamp this process holds, for every scope. */
    private static function flushTableChanges(): void
    {
        self::$trackedStamps = [];
    }
}
