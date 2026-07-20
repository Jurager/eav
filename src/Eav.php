<?php

declare(strict_types=1);

namespace Jurager\Eav;

use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Models\EntityTranslation;
use Jurager\Eav\Models\Locale;

class Eav
{
    /** @var class-string<Attribute> */
    public static string $attributeModel = Attribute::class;

    /** @var class-string<AttributeType> */
    public static string $attributeTypeModel = AttributeType::class;

    /** @var class-string<AttributeGroup> */
    public static string $attributeGroupModel = AttributeGroup::class;

    /** @var class-string<AttributeEnum> */
    public static string $attributeEnumModel = AttributeEnum::class;

    /** @var class-string<EntityAttribute> */
    public static string $entityAttributeModel = EntityAttribute::class;

    /** @var class-string<EntityTranslation> */
    public static string $entityTranslationModel = EntityTranslation::class;

    /** @var class-string<Locale> */
    public static string $localeModel = Locale::class;

    /** Set the model used to represent attributes. */
    public static function useAttributeModel(string $model): static
    {
        static::$attributeModel = $model;

        return new static();
    }

    /** Set the model used to represent attribute types. */
    public static function useAttributeTypeModel(string $model): static
    {
        static::$attributeTypeModel = $model;

        return new static();
    }

    /** Set the model used to represent attribute groups. */
    public static function useAttributeGroupModel(string $model): static
    {
        static::$attributeGroupModel = $model;

        return new static();
    }

    /** Set the model used to represent attribute enum options. */
    public static function useAttributeEnumModel(string $model): static
    {
        static::$attributeEnumModel = $model;

        return new static();
    }

    /** Set the model used to represent entity attribute values. */
    public static function useEntityAttributeModel(string $model): static
    {
        static::$entityAttributeModel = $model;

        return new static();
    }

    /** Set the model used to represent entity translations. */
    public static function useEntityTranslationModel(string $model): static
    {
        static::$entityTranslationModel = $model;

        return new static();
    }

    /** Set the model used to represent locales. */
    public static function useLocaleModel(string $model): static
    {
        static::$localeModel = $model;

        return new static();
    }
}
