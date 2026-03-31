<?php

namespace Jurager\Eav\Managers;

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
    private ?AttributeSchema $attributeSchema = null;

    private ?GroupSchema $groupSchema = null;

    private ?EnumSchema $enumSchema = null;

    private ?TypeSchema $typeSchema = null;

    public function __construct(
        protected TranslationManager $translations,
    ) {
    }

    public function translations(): TranslationManager
    {
        return $this->translations;
    }

    public function attribute(): AttributeSchema
    {
        return $this->attributeSchema ??= new AttributeSchema($this->translations);
    }

    public function group(): GroupSchema
    {
        return $this->groupSchema ??= new GroupSchema($this->translations);
    }

    public function enum(): EnumSchema
    {
        return $this->enumSchema ??= new EnumSchema($this->translations);
    }

    public function type(): TypeSchema
    {
        return $this->typeSchema ??= new TypeSchema();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function attributes(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute');

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function enums(Attribute $attribute, ?callable $modifier = null): mixed
    {
        $query = $attribute->enums()->getQuery();

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
    public function types(?callable $modifier = null): mixed
    {
        $query = EavModels::query('attribute_type');

        return $modifier ? $modifier($query) : $query->get();
    }

    /** @param  callable(\Illuminate\Database\Eloquent\Builder): mixed|null  $modifier */
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
