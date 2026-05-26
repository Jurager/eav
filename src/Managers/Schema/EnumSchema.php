<?php

namespace Jurager\Eav\Managers\Schema;

use Illuminate\Support\Facades\Event;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;

class EnumSchema extends BaseSchema
{
    protected function modelKey(): string
    {
        return 'attribute_enum';
    }

    public function find(int $id): AttributeEnum
    {
        /** @var AttributeEnum */
        return $this->query()->findOrFail($id);
    }

    public function create(Attribute $attribute, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeEnum $enum */
        $enum = $this->createRecord(fn () => $attribute->enums()->create($data), $translations);

        Event::dispatch(new AttributeEnumCreated($enum));

        return $enum;
    }

    public function update(AttributeEnum $enum, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeEnum $enum */
        $enum = $this->updateRecord($enum, $data, $translations);

        Event::dispatch(new AttributeEnumUpdated($enum->fresh()));

        return $enum;
    }

    public function delete(AttributeEnum $enum): void
    {
        Event::dispatch(new AttributeEnumDeleted($this->deleteRecord($enum)));
    }
}
