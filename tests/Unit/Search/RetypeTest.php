<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Illuminate\Pagination\LengthAwarePaginator;
use Jurager\Eav\Enums\CorrectionReason;
use Jurager\Eav\Search\Correction;
use Jurager\Eav\Search\Results;
use Jurager\Eav\Search\Retype;
use Jurager\Eav\Tests\Fixtures\Layouts;
use Jurager\Eav\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RetypeTest extends TestCase
{
    /**
     * @param  list<string>  $layouts
     * @return list<string> The texts searched for when nothing is found, in order.
     */
    private function tried(string $text, string $language, array $layouts): array
    {
        $tried = [];

        Retype::find($text, $language, $layouts, function (string $form) use (&$tried): Results {
            $tried[] = $form;

            return Results::of(new LengthAwarePaginator([], 0, 15));
        });

        return $tried;
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function russianTexts(): array
    {
        return [
            'english keys for russian letters' => ['irfa', ['шкаф']],
            'letters on punctuation keys' => ['ve;crbt', ['мужские']],
            'a punctuation key first' => ['[jkjlbkmybr', ['холодильник']],
            'russian keys for english letters' => ['шзрщту', ['iphone', 'shzrschtu']],
            'upper case' => ['IRFA', ['шкаф']],
            'digits and spaces stay' => ['irfa 100', ['шкаф 100']],
            'a word that would turn to signs' => ['хлеб', ['hleb']],
            'a brand' => ['самсунг', ['cfvceyu', 'samsung']],
            'letters as in a slug' => ['ящик хром', ['yaschik hrom']],
            'a text of both' => ['шкаф irfa', ['irfa шкаф', 'shkaf irfa']],
            'a text without letters' => ['100', []],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function latinTexts(): array
    {
        return [
            'an adjective' => ['ru', 'Белый шкаф', ['belyy shkaf']],
            'a soft sign' => ['ru', 'соль', ['sol']],
            'a latin word stays' => ['ru', 'самсунг galaxy', ['samsung galaxy']],
            'the way of another language' => ['uk', 'щітка', ['shchitka']],
            'a language with no way of its own' => ['uz', 'шкаф', ['skaf']],
            'another alphabet' => ['el', 'καλο', ['kalo']],
            'accents' => ['fr', 'Café', ['cafe']],
            'no change' => ['ru', 'samsung', []],
        ];
    }

    /**
     * @param  list<string>  $tried
     */
    #[DataProvider('russianTexts')]
    public function test_the_text_is_searched_for_on_the_other_layout_and_in_latin_letters(string $text, array $tried): void
    {
        $this->assertSame($tried, $this->tried($text, 'ru', [Layouts::RUSSIAN]));
    }

    /**
     * @param  list<string>  $tried
     */
    #[DataProvider('latinTexts')]
    public function test_letters_are_written_in_latin_ones_the_way_of_the_language(string $language, string $text, array $tried): void
    {
        $this->assertSame($tried, $this->tried($text, $language, []));
    }

    public function test_any_layout_is_the_letters_on_the_keys(): void
    {
        $this->assertSame(['καλο'], $this->tried('kalo', 'ru', [Layouts::GREEK]));
        $this->assertSame(['kalo'], $this->tried('καλο', 'ru', [Layouts::GREEK]));
        $this->assertSame(['shkaf'], $this->tried('шкаф', 'ru', [Layouts::GREEK]));
    }

    public function test_every_layout_is_tried_and_no_text_twice(): void
    {
        $this->assertSame(['шкаф', 'ιρφα'], $this->tried('irfa', 'ru', [Layouts::RUSSIAN, Layouts::GREEK]));
        $this->assertSame(['шкаф'], $this->tried('irfa', 'ru', [Layouts::RUSSIAN, Layouts::RUSSIAN]));
        $this->assertSame([], $this->tried('irfa', 'ru', []));
    }

    public function test_the_first_text_that_finds_something_wins(): void
    {
        $tried = [];

        $found = Retype::find('IRFA', 'ru', [Layouts::RUSSIAN, Layouts::GREEK], function (string $form) use (&$tried): Results {
            $tried[] = $form;

            return Results::of(new LengthAwarePaginator($tried === ['шкаф'] ? ['hit'] : [], $tried === ['шкаф'] ? 1 : 0, 15));
        });

        $this->assertSame(['шкаф'], $tried);
        $this->assertEquals(new Correction('IRFA', 'шкаф', CorrectionReason::Layout), $found?->correction());
    }
}
