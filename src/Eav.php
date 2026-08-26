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
    public static function useAttributeModel(string $model): void
    {
        static::$attributeModel = $model;
    }

    /** Set the model used to represent attribute types. */
    public static function useAttributeTypeModel(string $model): void
    {
        static::$attributeTypeModel = $model;
    }

    /** Set the model used to represent attribute groups. */
    public static function useAttributeGroupModel(string $model): void
    {
        static::$attributeGroupModel = $model;
    }

    /** Set the model used to represent attribute enum options. */
    public static function useAttributeEnumModel(string $model): void
    {
        static::$attributeEnumModel = $model;
    }

    /** Set the model used to represent entity attribute values. */
    public static function useEntityAttributeModel(string $model): void
    {
        static::$entityAttributeModel = $model;
    }

    /** Set the model used to represent entity translations. */
    public static function useEntityTranslationModel(string $model): void
    {
        static::$entityTranslationModel = $model;
    }

    /** Set the model used to represent locales. */
    public static function useLocaleModel(string $model): void
    {
        static::$localeModel = $model;
    }
}
