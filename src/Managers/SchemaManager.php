<?php

namespace Jurager\Eav\Managers;

use Illuminate\Database\Eloquent\Builder;
use Jurager\Eav\Exceptions\SearchNotAvailableException;
use Jurager\Eav\Managers\Schema\AttributeSchema;
use Jurager\Eav\Managers\Schema\EnumSchema;
use Jurager\Eav\Managers\Schema\GroupSchema;
use Jurager\Eav\Managers\Schema\TypeSchema;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Support\EavModels;

/**
 * Manages the EAV attribute schema: attributes, groups, and enums.
 *
 * Responsible for create/read/update/delete/sort operations on attribute definitions.
 * For reading and writing attribute *values* on entities, use AttributeManager.
 */
class SchemaManager
{
    public function __construct(
        private TranslationManager $translations,
        private AttributeSchema $attributeSchema,
        private GroupSchema $groupSchema,
        private EnumSchema $enumSchema,
        private TypeSchema $typeSchema,
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

    public function type(): TypeSchema
    {
        return $this->typeSchema;
    }

    /** @param  callable(Builder): mixed|null  $modifier */
    public function attributes(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute');

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(Builder): mixed|null  $modifier */
    public function enums(Attribute $attribute, ?callable $modifier = null): mixed
    {
        $query = $attribute->enums()->getQuery();

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(Builder): mixed|null  $modifier */
    public function types(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute_type');

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(Builder): mixed|null  $modifier */
    public function groups(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute_group');

        return $modifier ? $modifier($query) : $query->get();
    }

    /**
     * Initiate a full-text search on attributes via Laravel Scout.
     *
     * @param  callable(mixed): mixed|null  $modifier
     *
     * @throws SearchNotAvailableException
     */
    public function search(string $query, ?callable $modifier = null): mixed
    {
        $modelClass = EavModels::class('attribute');

        if (! method_exists($modelClass, 'search')) {
            throw SearchNotAvailableException::scoutNotInstalled();
        }

        $builder = $modelClass::search($query);

        return $modifier ? $modifier($builder) : $builder;
    }
}
