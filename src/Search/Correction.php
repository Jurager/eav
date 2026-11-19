<?php

declare(strict_types=1);

namespace Jurager\Eav\Search;

use Illuminate\Contracts\Support\Arrayable;
use Jurager\Eav\Enums\CorrectionReason;

/**
 * @implements Arrayable<string, string|null>
 */
final readonly class Correction implements Arrayable
{
    /** Stores the original query, suggested correction, and correction reason. */
    public function __construct(
        public string $original,
        public ?string $corrected,
        public CorrectionReason $reason,
    ) {}

    /**
     * The correction the best hit shows, or null when it holds the text as it was written.
     *
     * @param  array<string, mixed>  $hit
     */
    public static function detect(string $text, array $hit): ?self
    {
        $details = $hit['_rankingScoreDetails'] ?? null;

        if (! is_array($details)) {
            return null;
        }

        $partial = ($details['words']['matchingWords'] ?? 0) < ($details['words']['maxMatchingWords'] ?? 0);

        if (! $partial && ($details['typo']['typoCount'] ?? 0) <= 0) {
            return null;
        }

        $asked = self::words($text);
        $written = self::written($asked, self::highlighted($hit['_formatted'] ?? []));

        return new self(
            $text,
            $written !== [] && $written !== $asked ? implode(' ', $written) : null,
            $partial ? CorrectionReason::Partial : CorrectionReason::Typo,
        );
    }

    /**
     * @return array{original: string, corrected: string|null, reason: string}
     */
    public function toArray(): array
    {
        return ['original' => $this->original, 'corrected' => $this->corrected, 'reason' => $this->reason->value];
    }

    /**
     * The words asked for as the hit writes them; one the hit does not hold drops out.
     *
     * @param  list<string>  $asked
     * @param  list<string>  $highlighted
     * @return list<string>
     */
    private static function written(array $asked, array $highlighted): array
    {
        $written = [];

        foreach ($asked as $word) {
            $nearest = null;
            $typos = min(2, intdiv(mb_strlen($word), 3));

            foreach ($highlighted as $candidate) {
                if (str_starts_with($candidate, $word)) {
                    $nearest = [0, $word];

                    break;
                }

                $distance = self::distance($word, $candidate);

                if ($distance <= $typos && $distance < ($nearest[0] ?? PHP_INT_MAX)) {
                    $nearest = [$distance, $candidate];
                }
            }

            if ($nearest !== null) {
                $written[] = $nearest[1];
            }
        }

        return $written;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return preg_split('~[^\p{L}\p{N}]+~u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The highlighted parts and the whole words they sit in: the engine highlights only what it matched.
     *
     * @return list<string>
     */
    private static function highlighted(mixed $formatted): array
    {
        $words = [];

        if (is_array($formatted)) {
            array_walk_recursive($formatted, static function (mixed $value) use (&$words): void {
                if (is_string($value) && preg_match_all('~([\p{L}\p{N}]*)<em>(.+?)</em>([\p{L}\p{N}]*)~su', $value, $found, PREG_SET_ORDER) > 0) {
                    foreach ($found as [, $before, $part, $after]) {
                        array_push($words, $part, $before.$part.$after);
                    }
                }
            });
        }

        return array_values(array_unique(array_map(mb_strtolower(...), $words)));
    }

    /**
     * The typos between two words, counted in letters; two neighbours swapped count as one.
     */
    private static function distance(string $from, string $to): int
    {
        $from = mb_str_split($from);
        $to = mb_str_split($to);
        $rows = [range(0, count($to))];

        for ($i = 1; $i <= count($from); $i++) {
            $rows[$i] = [$i];

            for ($j = 1; $j <= count($to); $j++) {
                $rows[$i][$j] = min(
                    $rows[$i - 1][$j] + 1,
                    $rows[$i][$j - 1] + 1,
                    $rows[$i - 1][$j - 1] + ($from[$i - 1] === $to[$j - 1] ? 0 : 1),
                );

                if ($i > 1 && $j > 1 && $from[$i - 1] === $to[$j - 2] && $from[$i - 2] === $to[$j - 1]) {
                    $rows[$i][$j] = min($rows[$i][$j], $rows[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $rows[count($from)][count($to)];
    }
}
