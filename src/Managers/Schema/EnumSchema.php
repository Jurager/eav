<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers\Schema;

use Jurager\Eav\Eav;
use Jurager\Eav\Events\AttributeEnumCreated;
use Jurager\Eav\Events\AttributeEnumDeleted;
use Jurager\Eav\Events\AttributeEnumUpdated;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;

class EnumSchema extends BaseSchema
{
    /** Find an enum by ID. */
    public function find(int $id): AttributeEnum
    {
        /** @var AttributeEnum */
        return $this->query()->findOrFail($id);
    }

    /** Create a new enum for the given attribute. */
    public function create(Attribute $attribute, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeEnum $enum */
        $enum = $this->createRecord(fn () => $attribute->enums()->create($data), $translations);

        $this->events->dispatch(new AttributeEnumCreated($enum));

        return $enum;
    }

    /** Update an existing enum. */
    public function update(AttributeEnum $enum, array $data): AttributeEnum
    {
        $translations = $this->extractTranslations($data);

        /** @var AttributeEnum $enum */
        $enum = $this->updateRecord($enum, $data, $translations);

        $this->events->dispatch(new AttributeEnumUpdated($enum->fresh()));

        return $enum;
    }

    /** Delete an enum. */
    public function delete(AttributeEnum $enum): void
    {
        $this->events->dispatch(new AttributeEnumDeleted($this->deleteRecord($enum)));
    }

    /** Get the model class. */
    protected function modelClass(): string
    {
        return Eav::$attributeEnumModel;
    }
}
