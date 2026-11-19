<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

/**
 * Why a search was not run for the text it was asked for.
 */
enum CorrectionReason: string
{
    /** The best hit holds the words written otherwise: the engine put up with typos. */
    case Typo = 'typo';

    /** The best hit holds only some of the words: the engine skipped the rest. */
    case Partial = 'partial';

    /** Nothing was found for the text, but the text typed in the other keyboard layout found something. */
    case Layout = 'layout';

    /** Nothing was found for the text, but the text written in the other script found something. */
    case Transliteration = 'transliteration';
}
