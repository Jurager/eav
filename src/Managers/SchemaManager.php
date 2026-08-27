<?php

declare(strict_types=1);

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Builder;
use Jurager\Eav\Eav;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Managers\Schema\AttributeSchema;
use Jurager\Eav\Managers\Schema\EnumSchema;
use Jurager\Eav\Managers\Schema\GroupSchema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;

class SchemaManager
{
    public function __construct(
        private TranslationManager $translations,
        private AttributeSchema $attributeSchema,
        private GroupSchema $groupSchema,
        private EnumSchema $enumSchema,
    ) {
    }

    /** Get the translation manager. */
    public function translations(): TranslationManager
    {
        return $this->translations;
    }

    /** Get the attribute schema manager. */
    public function attribute(): AttributeSchema
    {
        return $this->attributeSchema;
    }

    /** Get the group schema manager. */
    public function group(): GroupSchema
    {
        return $this->groupSchema;
    }

    /** Get the enum schema manager. */
    public function enum(): EnumSchema
    {
        return $this->enumSchema;
    }

    /** Find an attribute type by ID. */
    public function findType(int $id): AttributeType
    {
        return Eav::$attributeTypeModel::query()->findOrFail($id);
    }

    /** @return Builder<Attribute> */
    public function attributesQuery(): Builder
    {
        return Eav::$attributeModel::query();
    }

    /** @return Builder<\Jurager\Eav\Models\AttributeEnum> */
    public function enumsQuery(Attribute $attribute): Builder
    {
        return $attribute->enums()->getQuery();
    }

    /** @return Builder<AttributeType> */
    public function typesQuery(): Builder
    {
        return Eav::$attributeTypeModel::query();
    }

    /** @return Builder<\Jurager\Eav\Models\AttributeGroup> */
    public function groupsQuery(): Builder
    {
        return Eav::$attributeGroupModel::query();
    }

    /**
     * Perform a search on attributes using Laravel Scout.
     * @throws SearchNotAvailableException
     */
    public function search(string $query): mixed
    {
        $modelClass = Eav::$attributeModel;

        if (! method_exists($modelClass, 'search')) {
            throw SearchNotAvailableException::scoutNotInstalled();
        }

        return $modelClass::search($query);
    }
}
