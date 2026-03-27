<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\EnumRegistry;

class AttributeEnumObserver
{
    public function saved(AttributeEnum $enum): void
    {
        app(EnumRegistry::class)->flush($enum->attribute_id);
    }

    public function deleted(AttributeEnum $enum): void
    {
        app(EnumRegistry::class)->flush($enum->attribute_id);
    }
}
