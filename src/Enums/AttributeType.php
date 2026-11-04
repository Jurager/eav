<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

enum AttributeType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
    case Image = 'image';
    case File = 'file';
    case Link = 'link';
}
