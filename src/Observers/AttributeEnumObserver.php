<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\EnumRegistry;

class AttributeEnumObserver
{
    public function __construct(
        protected EnumRegistry $enums,
    ) {
    }

    public function saved(AttributeEnum $enum): void
    {
        $this->enums->forget($enum->attribute_id);
    }

    public function deleted(AttributeEnum $enum): void
    {
        $this->enums->forget($enum->attribute_id);
    }
}
