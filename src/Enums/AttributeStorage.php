<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

/**
 * The `entity_attribute` column a field type stores its values in.
 */
enum AttributeStorage: string
{
    /**
     * Short strings used for exact matches and sorting.
     */
    case Text = 'value_text';

    /**
     * Text of any length, kept out of the exact-match index.
     */
    case LongText = 'value_long';

    case Integer = 'value_integer';
    case Float = 'value_float';
    case Boolean = 'value_boolean';
    case Date = 'value_date';
    case Datetime = 'value_datetime';
}
