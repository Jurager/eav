<?php

declare(strict_types=1);

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Jurager\Eav\Registry\SchemaRegistry;

class AttributeScope extends Pivot
{
    protected static function booted(): void
    {
        static::saved(static fn () => app(SchemaRegistry::class)->forget());
        static::deleted(static fn () => app(SchemaRegistry::class)->forget());
    }
}
