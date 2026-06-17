<?php

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Builder;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Managers\Schema\AttributeSchema;
use Jurager\Eav\Managers\Schema\EnumSchema;
use Jurager\Eav\Managers\Schema\GroupSchema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

/**
 * Manages EAV attribute schema definitions.
 */
class SchemaManager
{
    public function __construct(
        private TranslationManager $translations,
        private AttributeSchema $attributeSchema,
        private GroupSchema $groupSchema,
        private EnumSchema $enumSchema,
    ) {}

    public function translations(): TranslationManager
    {
        return $this->translations;
    }

    public function attribute(): AttributeSchema
    {
        return $this->attributeSchema;
    }

    public function group(): GroupSchema
    {
        return $this->groupSchema;
    }

    public function enum(): EnumSchema
    {
        return $this->enumSchema;
    }

    public function findType(int $id): AttributeType
    {
        return EavModels::query('attribute_type')->findOrFail($id);
    }

    public function attributesQuery(): Builder
    {
        return EavModels::query('attribute');
    }

    public function enumsQuery(Attribute $attribute): Builder
    {
        return $attribute->enums()->getQuery();
    }

    public function typesQuery(): Builder
    {
        return EavModels::query('attribute_type');
    }

    public function groupsQuery(): Builder
    {
        return EavModels::query('attribute_group');
    }

    /**
     * @throws SearchNotAvailableException
     */
    public function search(string $query): mixed
    {
        $modelClass = EavModels::class('attribute');

        if (! method_exists($modelClass, 'search')) {
            throw SearchNotAvailableException::scoutNotInstalled();
        }

        return $modelClass::search($query);
    }
}
