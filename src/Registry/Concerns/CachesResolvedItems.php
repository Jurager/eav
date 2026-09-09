<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry\Concerns;

use Closure;

/** Resolve one item at a time by key within a named partition, remembering both hits and misses. */
trait CachesResolvedItems
{
    /** @var array<string, array<int|string, mixed>> */
    private static array $resolvedItems = [];

    /** @var array<string, array<int|string, true>> */
    private static array $unresolvedKeys = [];

    /** Get an item by key within a partition, querying for it (once) if neither resolved nor already known to be missing. */
    private function resolved(string $partition, int|string $key, Closure $query): mixed
    {
        if (array_key_exists($key, self::$resolvedItems[$partition] ?? [])) {
            return self::$resolvedItems[$partition][$key];
        }

        if (isset(self::$unresolvedKeys[$partition][$key])) {
            return null;
        }

        $item = $query();

        if ($item === null) {
            self::$unresolvedKeys[$partition][$key] = true;

            return null;
        }

        return self::$resolvedItems[$partition][$key] = $item;
    }

    /** Drop the resolved-item cache for one partition, or every partition. */
    private function forgetResolved(?string $partition = null): void
    {
        if ($partition === null) {
            self::$resolvedItems = [];
            self::$unresolvedKeys = [];

            return;
        }

        unset(self::$resolvedItems[$partition], self::$unresolvedKeys[$partition]);
    }

    /** Drop every resolved-item cache this process holds, for every partition. */
    private static function flushResolved(): void
    {
        self::$resolvedItems = [];
        self::$unresolvedKeys = [];
    }
}
