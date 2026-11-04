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
use Jurager\Eav\Models\AttributeEntityType;
use Jurager\Eav\Models\AttributeEnum;
use Jurager\Eav\Models\AttributeGroup;
use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Models\EntityAttribute;
use Jurager\Eav\Models\EntityTranslation;
use Jurager\Eav\Models\Locale;

return [

    /*
     * The Eloquent models used by the package. You may replace a model with
     * one of your own, provided it extends the corresponding package model.
     */
    'models' => [
        'attribute' => Attribute::class,
        'attribute_type' => AttributeType::class,
        'attribute_group' => AttributeGroup::class,
        'attribute_enum' => AttributeEnum::class,
        'attribute_entity_type' => AttributeEntityType::class,
        'entity_attribute' => EntityAttribute::class,
        'entity_translation' => EntityTranslation::class,
        'locale' => Locale::class,
    ],

    /*
     * The maximum number of parent levels to check when inheriting attributes.
     * Exceeding this limit throws a CircularInheritanceException. This limit
     * applies to parent-key hierarchies, not nested-set trees.
     */
    'max_inheritance_depth' => 10,

    'cache' => [

        /*
         * The cache store used to notify application instances of schema changes.
         * Use a store shared by all instances, or null for the default store.
         *
         * After changing schema data through raw SQL or mass updates, run
         * `php artisan eav:clear` to refresh the cached definitions.
         */
        'store' => env('EAV_CACHE_STORE'),
    ],

    /*
     * The maximum number of bound parameters in a bulk write statement.
     * Leave this null to use the database driver's limit. Set a lower value
     * if your database has a stricter limit.
     */
    'bind_limit' => null,

    /*
     * The Laravel validation rules for attribute validation codes. You may
     * add your own codes or override the existing mappings.
     *
     * A validation with type "min_length" and value 3 becomes the rule "min:3".
     */
    'validations' => [
        'min_length' => 'min',
        'max_length' => 'max',
        'min' => 'min',
        'max' => 'max',
        'regex' => 'regex',
        'email' => 'email',
        'url' => 'url',
        'date_format' => 'date_format',
        'after' => 'after',
        'before' => 'before',
    ],

    /*
     * The field classes used for each attribute type code. You may replace
     * built-in fields or add your own classes extending Field.
     *
     * Each code must also exist in the attribute_types table.
     */
    'types' => [
        'text' => Text::class,
        'textarea' => Textarea::class,
        'number' => Number::class,
        'date' => Date::class,
        'boolean' => Boolean::class,
        'select' => Select::class,
        'image' => Image::class,
        'file' => File::class,
        'link' => Link::class,
    ],

];
