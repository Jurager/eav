<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Jurager\Eav\Enums\CorrectionReason;
use Jurager\Eav\Search\Correction;
use Jurager\Eav\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CorrectionTest extends TestCase
{
    /**
     * What the engine tells of a hit when asked for the ranking details and the highlights.
     *
     * @return array<string, mixed>
     */
    private static function hit(string $highlighted, int $typos = 0, int $found = 1, int $asked = 1): array
    {
        return [
            '_rankingScoreDetails' => [
                'words' => ['matchingWords' => $found, 'maxMatchingWords' => $asked],
                'typo' => ['typoCount' => $typos],
            ],
            '_formatted' => ['id' => '1', 'attributes' => ['title' => ['en' => $highlighted]]],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string|null, 3: CorrectionReason}>
     */
    public static function corrections(): array
    {
        return [
            'a typo' => ['шкарф', self::hit('<em>Шкаф</em> распашной', typos: 1), 'шкаф', CorrectionReason::Typo],
            'a typo in the word the hit goes on with' => ['каркас шкарф', self::hit('<em>Каркас</em> <em>шкаф</em>а Лион', typos: 1, found: 2, asked: 2), 'каркас шкаф', CorrectionReason::Typo],
            'a typo in one of several words' => ['шкаф белай', self::hit('<em>Шкаф</em> зеркальный цвет <em>белый</em>', typos: 1, found: 2, asked: 2), 'шкаф белый', CorrectionReason::Typo],
            'two letters swapped' => ['диавн', self::hit('<em>Диван</em> угловой', typos: 1), 'диван', CorrectionReason::Typo],
            'two typos in a long word' => ['распошнои', self::hit('<em>Распашной</em> шкаф', typos: 2), 'распашной', CorrectionReason::Typo],
            'a word the engine highlights in part' => ['jaket', self::hit('Blue winter <em>jacke</em>t', typos: 1), 'jacket', CorrectionReason::Typo],
            'a word typed in part' =>['шка белай', self::hit('<em>Шка</em>ф зеркальный цвет <em>белый</em>', typos: 1, found: 2, asked: 2), 'шка белый', CorrectionReason::Typo],
            'a word nothing holds' => ['шкаф zzzz', self::hit('<em>Шкаф</em> распашной', found: 1, asked: 2), 'шкаф', CorrectionReason::Partial],
            'a typo and a word nothing holds' => ['шкарф zzzz', self::hit('<em>Шкаф</em> распашной', typos: 1, found: 1, asked: 2), 'шкаф', CorrectionReason::Partial],
            'the case and the punctuation of the text' => ['ШКАРФ!', self::hit('<em>Шкаф</em> распашной', typos: 1), 'шкаф', CorrectionReason::Typo],
            'highlights that tell nothing' => ['шкарф', self::hit('Шкаф распашной', typos: 1), null, CorrectionReason::Typo],
        ];
    }

    #[DataProvider('corrections')]
    public function test_the_words_are_reported_as_the_hit_writes_them(string $text, array $hit, ?string $corrected, CorrectionReason $reason): void
    {
        $correction = Correction::detect($text, $hit);

        $this->assertSame([$text, $corrected, $reason], [$correction?->original, $correction?->corrected, $correction?->reason]);
        $this->assertSame(['original' => $text, 'corrected' => $corrected, 'reason' => $reason->value], $correction->toArray());
    }

    public function test_a_text_the_hit_holds_as_it_is_written_is_not_corrected(): void
    {
        $this->assertNull(Correction::detect('шкаф', self::hit('<em>Шкаф</em> распашной')));
        $this->assertNull(Correction::detect('шкаф', ['_rankingScoreDetails' => ['words' => [], 'typo' => []]]));
    }

    public function test_a_hit_the_engine_told_nothing_of_is_not_corrected(): void
    {
        $this->assertNull(Correction::detect('шкарф', ['id' => 1, '_rankingScore' => 0.4]));
    }
}
