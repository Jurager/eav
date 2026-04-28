<?php

use Jurager\Eav\Fields\BooleanField;
use Jurager\Eav\Fields\DateField;
use Jurager\Eav\Fields\FileField;
use Jurager\Eav\Fields\ImageField;
use Jurager\Eav\Fields\LinkField;
use Jurager\Eav\Fields\NumberField;
use Jurager\Eav\Fields\SelectField;
use Jurager\Eav\Fields\TextAreaField;
use Jurager\Eav\Fields\TextField;
use Jurager\Eav\Models\Attribute;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Models\EntityTranslation;
use Jurager\Eav\Models\Locale;

return [

    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    | Override any model with your own subclass. The package resolves every
    | model reference through this map so your extensions are picked up
    | everywhere automatically.
    |
    | Domain-specific models (e.g. Measurement, MeasurementUnit) are not listed
    | here — add them in your app's published config if you use MeasurementField.
    */
    'models' => [
        'attribute' => Attribute::class,
        'attribute_type' => AttributeType::class,
        'attribute_group' => AttributeGroup::class,
        'attribute_enum' => AttributeEnum::class,
        'entity_attribute' => EntityAttribute::class,
        'entity_translation' => EntityTranslation::class,
        'locale' => Locale::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribute Inheritance
    |--------------------------------------------------------------------------
    | Maximum number of ancestor levels to traverse when resolving attribute
    | inheritance chains (parent_id strategy). Increase for deep hierarchies.
    */
    'max_inheritance_depth' => 10,

    /*
    |--------------------------------------------------------------------------
    | Field Types
    |--------------------------------------------------------------------------
    | Maps attribute type codes (attribute_types.code) to Field implementations.
    | Register custom types here or override existing ones.
    |
    | Domain-specific field types (e.g. MeasurementField) are not listed here.
    | Add them in your app's published config:
    |
    |   'types' => [
    |       ...
    |       'measurement' => \Jurager\Eav\Fields\MeasurementField::class,
    |   ],
    */
    'types' => [
        'text' => TextField::class,
        'textarea' => TextAreaField::class,
        'number' => NumberField::class,
        'date' => DateField::class,
        'boolean' => BooleanField::class,
        'select' => SelectField::class,
        'image' => ImageField::class,
        'file' => FileField::class,
        'link' => LinkField::class,
    ],


];
