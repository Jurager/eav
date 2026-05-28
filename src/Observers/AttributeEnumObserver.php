<?php

namespace Jurager\Eav\Observers;

use Jurager\Eav\Jobs\SyncSearchable;
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
        $this->syncSearchable($enum);
    }

    public function deleted(AttributeEnum $enum): void
    {
        $this->enums->forget($enum->attribute_id);
        $this->syncSearchable($enum);
    }

    protected function syncSearchable(AttributeEnum $enum): void
    {
        $attribute = $enum->attribute;

        if ($attribute?->searchable) {
            SyncSearchable::dispatch($attribute->entity_type, $attribute->id)->afterCommit();
        }
    }
}
