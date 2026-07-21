<?php

use Jurager\Eav\Fields\Boolean;
use Jurager\Eav\Fields\Date;
use Jurager\Eav\Fields\File;
use Jurager\Eav\Fields\Image;
use Jurager\Eav\Fields\Link;
use Jurager\Eav\Fields\Number;
use Jurager\Eav\Fields\Select;
use Jurager\Eav\Fields\Text;
use Jurager\Eav\Fields\Textarea;
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
    | Validation Rule Map
    |--------------------------------------------------------------------------
    | Maps validation type codes stored on attributes to Laravel rule prefixes.
    | Add or override entries here to support additional validation types
    | without modifying the package.
    |
    | For rules that take a parameter (min, max, regex, …) the package appends
    | ":<value>" automatically. Parameterless rules (email, url) are used as-is.
    */
    'validations' => [
        'min_length'  => 'min',
        'max_length'  => 'max',
        'min'         => 'min',
        'max'         => 'max',
        'regex'       => 'regex',
        'email'       => 'email',
        'url'         => 'url',
        'date_format' => 'date_format',
        'after'       => 'after',
        'before'      => 'before',
    ],

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
        'text'     => Text::class,
        'textarea' => Textarea::class,
        'number'   => Number::class,
        'date'     => Date::class,
        'boolean'  => Boolean::class,
        'select'   => Select::class,
        'image'    => Image::class,
        'file'     => File::class,
        'link'     => Link::class,
    ],

];
