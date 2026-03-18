<?php

namespace Jurager\Eav\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves EAV model classes from config, allowing apps to swap in their own subclasses.
 *
 * Usage:
 *   EavModels::has('measurement_unit')     → bool
 *   EavModels::class('attribute')          → class-string
 *   EavModels::query('entity_attribute')   → Builder
 *   EavModels::make('entity_translation')  → Model instance
 */
class EavModels
{
    /**
     * Check whether a model key is configured.
     * Useful for optional domain models (e.g. measurement_unit) that not every app provides.
     */
    public static function has(string $key): bool
    {
        return (bool) config("eav.models.{$key}");
    }

    public static function class(string $key): string
    {
        return config("eav.models.{$key}")
            ?? throw new \InvalidArgumentException("EAV model '{$key}' is not configured in eav.models.");
    }

    public static function query(string $key): Builder
    {
        return (static::class($key))::query();
    }

    public static function make(string $key): Model
    {
        $class = static::class($key);

        return new $class();
    }
}
