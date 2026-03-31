<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Support\EavModels;

class EnumSchema extends BaseSchema
{
    public function find(int $id): AttributeEnum
    {
        return EavModels::query('attribute_enum')->findOrFail($id);
    }

    public function create(Attribute $attribute, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        $enum = $attribute->enums()->create($data);

        $this->saveTranslations($enum, $translations);

        Event::dispatch(new AttributeEnumCreated($enum));

        return $enum;
    }

    public function update(AttributeEnum $enum, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        $enum->update($data);

        $this->saveTranslations($enum, $translations);

        Event::dispatch(new AttributeEnumUpdated($enum->fresh()));

        return $enum;
    }

    public function delete(AttributeEnum $enum): void
    {
        $snapshot = clone $enum;

        $enum->delete();

        Event::dispatch(new AttributeEnumDeleted($snapshot));
    }
}
