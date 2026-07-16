<?php

declare(strict_types=1);

namespace Jurager\Eav\Observers;

use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Jobs\SyncSearchable;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Registry\EnumRegistry;

class AttributeEnumObserver
{
    public function __construct(
        protected EnumRegistry $enums,
    ) {
    }

    /** Handle the "saved" event for the enum. */
    public function saved(AttributeEnum $enum): void
    {
        $this->handle($enum);

        $enum->wasRecentlyCreated
            ? AttributeEnumCreated::dispatch($enum)
            : AttributeEnumUpdated::dispatch($enum);
    }

    /** Handle the "deleted" event for the enum. */
    public function deleted(AttributeEnum $enum): void
    {
        $this->handle($enum);

        AttributeEnumDeleted::dispatch($enum);
    }

    /** Handle common post-change tasks: clear cache and sync searchable state. */
    protected function handle(AttributeEnum $enum): void
    {
        $this->enums->forget($enum->attribute_id);

        if ($enum->attribute?->searchable) {
            SyncSearchable::dispatch($enum->attribute->entity_type, $enum->attribute_id)
                ->afterCommit();
        }
    }
}
