<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\EnumRegistry;
use Jurager\Eav\Search\Indexer;

/**
 * Refresh cached options and indexed labels when an option changes.
 */
class AttributeEnumObserver
{
    public function __construct(
        protected EnumRegistry $enums,
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

        if (Indexer::available() && $enum->attribute?->searchable) {
            foreach ($enum->attribute->applicableEntityTypes() as $entityType) {
                SyncSearchable::dispatch($entityType, $enum->attribute_id)->afterCommit();
            }
        }
    }
}
