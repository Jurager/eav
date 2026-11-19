<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Closure;
use Illuminate\Support\Str;
use Jurager\Eav\Enums\CorrectionReason;

/**
 * Searches again for a text typed another way: on another keyboard layout, or in Latin letters.
 */
final class Retype
{
    private const string KEYS = "qwertyuiop[]asdfghjkl;'zxcvbnm,.`";

    /**
     * The results of the first form of the text that finds something: on each layout, then in Latin letters.
     *
     * @param  list<string>  $layouts
     * @param  Closure(string): Results  $search
     */
    public static function find(string $typed, string $language, array $layouts, Closure $search): ?Results
    {
        $text = mb_strtolower($typed);
        $forms = [];

        foreach ($layouts as $letters) {
            $forms[] = [CorrectionReason::Layout, self::layout($text, $letters)];
        }

        $forms[] = [CorrectionReason::Transliteration, Str::ascii($text, $language)];

        $tried = [$text];

        foreach ($forms as [$reason, $form]) {
            if (in_array($form, $tried, true)) {
                continue;
            }

            $tried[] = $form;
            $found = $search($form);

            if ($found->total() > 0) {
                return Results::of($found, $found->facets(), new Correction($typed, $found->correction()->corrected ?? $form, $reason));
            }
        }

        return null;
    }

    /**
     * The text as the same keys give on the layout, either way; the text itself when that is not words.
     */
    private static function layout(string $text, string $letters): string
    {
        $keys = array_combine(mb_str_split(self::KEYS), mb_str_split($letters));
        $retyped = strtr($text, $keys + array_flip($keys));

        return preg_match('~^[\p{L}\d\s]+$~u', $retyped) === 1 ? $retyped : $text;
    }
}
