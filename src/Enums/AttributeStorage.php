<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

enum AttributeStorage: string
{
    case Text = 'value_text';
    case Integer = 'value_integer';
    case Float = 'value_float';
    case Boolean = 'value_boolean';
    case Date = 'value_date';
    case Datetime = 'value_datetime';
}
