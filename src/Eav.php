<?php

declare(strict_types=1);

namespace Jurager\Eav;

use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEntityType;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Models\EntityTranslation;
use Jurager\Eav\Models\Locale;

/**
 * The model classes the package works with — set from `eav.models` in config/eav.php.
 */
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

    /** @var class-string<AttributeEntityType> */
    public static string $attributeEntityTypeModel = AttributeEntityType::class;

    /** @var class-string<EntityAttribute> */
    public static string $entityAttributeModel = EntityAttribute::class;

    /** @var class-string<EntityTranslation> */
    public static string $entityTranslationModel = EntityTranslation::class;

    /** @var class-string<Locale> */
    public static string $localeModel = Locale::class;
}
