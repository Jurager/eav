<?php

declare(strict_types=1);

namespace Jurager\Eav\Registry\Concerns;

use Jurager\Eav\Registry\SchemaVersion;

/**
 * Keep what a registry holds across requests until the schema version moves.
 */
trait FollowsSchemaVersion
{
    /**
     * The schema version what the registry holds was read under.
     */
    private static ?string $heldVersion = null;

    /**
     * Whether the schema moved since the registry last looked — true once per move.
     */
    private function schemaMoved(): bool
    {
        $version = app(SchemaVersion::class)->current();

        if (self::$heldVersion === $version) {
            return false;
        }

        self::$heldVersion = $version;

        return true;
    }

    /**
     * Tell every process the schema changed; this registry has already dropped what it held.
     */
    private function schemaChanged(): void
    {
        $version = app(SchemaVersion::class);
        $version->bump();

        self::$heldVersion = $version->current();
    }

    private static function forgetSchemaVersion(): void
    {
        self::$heldVersion = null;
    }
}
