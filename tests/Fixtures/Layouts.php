<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

/**
 * Keyboard layouts as `eav.search.layouts` takes them: the letters on the keys of a US keyboard, in the order the package lists the keys.
 */
final class Layouts
{
    public const string RUSSIAN = 'йцукенгшщзхъфывапролджэячсмитьбюё';

    public const string GREEK = ";ςερτυθιοπ[]ασδφγηξκλ΄'ζχψωβνμ,.`";
}
