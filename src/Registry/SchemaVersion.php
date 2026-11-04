<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Track schema changes across processes using the shared cache.
 */
class SchemaVersion
{
    private const string KEY = 'eav:schema-version';

    private ?string $current = null;

    public function __construct(private readonly Cache $cache) {}

    /**
     * Get the schema version for the current request or job.
     */
    public function current(): string
    {
        if ($this->current === null) {
            try {
                $this->current = (string) $this->cache->get(self::KEY, '');
            } catch (Throwable) {
                $this->current = '';
            }
        }

        return $this->current;
    }

    /**
     * Invalidate the schema across processes.
     */
    public function bump(): void
    {
        $this->current = (string) Str::uuid();

        try {
            $this->cache->forever(self::KEY, $this->current);
        } catch (Throwable) {
            // Unreachable cache: this process moves on, the others catch up with the next change.
        }
    }
}
