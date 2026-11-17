<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Search\Index\Synchronizer;

/**
 * Refresh cached options and indexed labels when an option changes.
 */
class AttributeEnumObserver
{
    public function __construct(
        protected EnumRegistry $enums,
        protected Synchronizer $indexes,
    ) {
    }

    public function saved(AttributeEnum $enum): void
    {
        $this->refresh($enum);
    }

    public function deleted(AttributeEnum $enum): void
    {
        $this->refresh($enum);
    }

    protected function refresh(AttributeEnum $enum): void
    {
        $this->enums->forget($enum->attribute_id);

        $this->indexes->enum($enum);
    }
}
