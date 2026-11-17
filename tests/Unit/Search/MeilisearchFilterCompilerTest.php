<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Exceptions\InvalidSearchQueryException;
use Jurager\Eav\Search\Engines\Meilisearch\FilterCompiler;
use Jurager\Eav\Search\Filters\Normalizer;
use Jurager\Eav\Search\Index\Index;
use Jurager\Eav\Tests\TestCase;
use Jurager\Filterable\Parsing\FilterParser;

class MeilisearchFilterCompilerTest extends TestCase
{
    private Index $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = new Index(new class extends Model
        {
            public function searchableAs(): string
            {
                return 'things';
            }
        }, 'thing', ['en', 'ru']);

        $this->index->field('brand')->keyword()->filterable()->alias('maker');
        $this->index->field('price')->number()->filterable();
        $this->index->field('released')->date()->filterable();
        $this->index->field('active')->boolean()->filterable();
        $this->index->field('title')->filterable()->localized();
        $this->index->field('notes');
    }

    private function compile(array $filter, ?string $locale = 'en', array $except = []): ?string
    {
        $group = (new Normalizer($this->index))->normalize((new FilterParser)->parse($filter, []));

        return app(FilterCompiler::class)->compile($group->except($except), $locale);
    }

    public function test_a_scalar_is_an_equality_cast_to_the_field_type(): void
    {
        $this->assertSame('brand = "nike"', $this->compile(['brand' => 'nike']));
        $this->assertSame('brand = "007"', $this->compile(['brand' => '007']));
        $this->assertSame('price = 100', $this->compile(['price' => '100']));
        $this->assertSame('price = 9.5', $this->compile(['price' => '9.5']));
        $this->assertSame('active = true', $this->compile(['active' => 'true']));
        $this->assertSame('active = false', $this->compile(['active' => '0']));
    }

    public function test_dates_are_compared_as_timestamps(): void
    {
        $this->assertSame(
            'released >= '.strtotime('2026-01-01'),
            $this->compile(['released' => ['gte' => '2026-01-01']]),
        );
    }

    public function test_operators(): void
    {
        $this->assertSame('brand != "nike"', $this->compile(['brand' => ['ne' => 'nike']]));
        $this->assertSame('price > 10', $this->compile(['price' => ['gt' => 10]]));
        $this->assertSame('(price >= 10 AND price <= 20)', $this->compile(['price' => ['gte' => 10, 'lte' => 20]]));
        $this->assertSame('brand IN ["nike", "puma"]', $this->compile(['brand' => ['nike', 'puma']]));
        $this->assertSame('brand IN ["nike", "puma"]', $this->compile(['brand' => ['in' => 'nike,puma']]));
        $this->assertSame('NOT brand IN ["nike"]', $this->compile(['brand' => ['nin' => ['nike']]]));
        $this->assertSame('(price >= 1 AND price <= 5)', $this->compile(['price' => ['between' => '1,5']]));
        $this->assertSame('(price < 1 OR price > 5)', $this->compile(['price' => ['not_between' => [1, 5]]]));
        $this->assertSame('brand NOT EXISTS', $this->compile(['brand' => ['null' => 'true']]));
        $this->assertSame('brand EXISTS', $this->compile(['brand' => ['not_null' => 'true']]));
    }

    public function test_a_field_answers_to_its_alias(): void
    {
        $this->assertSame('brand = "nike"', $this->compile(['maker' => 'nike']));
    }

    public function test_a_localized_field_is_compared_in_the_given_locale(): void
    {
        $this->assertSame('title.ru = "Кеды"', $this->compile(['title' => 'Кеды'], 'ru'));
    }

    public function test_conditions_and_groups_are_combined(): void
    {
        $this->assertSame(
            'brand = "nike" AND (price > 10 OR active = true)',
            $this->compile(['brand' => 'nike', 'or' => [['price' => ['gt' => 10]], ['active' => 'true']]]),
        );
    }

    public function test_conditions_of_excepted_fields_are_left_out(): void
    {
        $this->assertSame('price = 1', $this->compile(['maker' => 'nike', 'price' => 1], except: ['brand']));
        $this->assertNull($this->compile(['brand' => 'nike'], except: ['brand']));
    }

    public function test_a_field_with_several_operators_is_excepted_as_a_whole(): void
    {
        $this->assertSame('brand = "nike"', $this->compile(['brand' => 'nike', 'price' => ['gte' => 1, 'lte' => 5]], except: ['price']));
    }

    public function test_null_checks_can_be_negated(): void
    {
        $this->assertSame('brand EXISTS', $this->compile(['brand' => ['null' => 'false']]));
        $this->assertSame('brand NOT EXISTS', $this->compile(['brand' => ['not_null' => '0']]));
    }

    public function test_string_values_are_escaped(): void
    {
        $this->assertSame('brand = "a\"b\\\\c"', $this->compile(['brand' => 'a"b\\c']));
    }

    public function test_a_filter_key_cannot_inject_an_expression(): void
    {
        $this->expectException(InvalidSearchQueryException::class);
        $this->expectExceptionMessage('is not part of the search index');

        $this->compile(['brand = "x" OR id > 0 OR brand' => 'y']);
    }

    public function test_an_unknown_field_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        $this->compile(['nope' => 1]);
    }

    public function test_a_field_that_is_not_filterable_is_rejected(): void
    {
        $this->expectExceptionMessage('field [notes] is not filterable');

        $this->compile(['notes' => 'x']);
    }

    public function test_an_unknown_field_inside_a_group_is_rejected_too(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        $this->compile(['or' => [['brand' => 'nike'], ['nope' => 1]]]);
    }

    public function test_a_value_that_does_not_fit_the_type_is_rejected(): void
    {
        $this->expectExceptionMessage('value of [price] is not valid');

        $this->compile(['price' => ['gte' => 'cheap']]);
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        $this->compile(['released' => ['gte' => 'not a date']]);
    }

    public function test_range_operators_are_rejected_on_text(): void
    {
        $this->expectExceptionMessage('operator [gt] is not supported on field [brand]');

        $this->compile(['brand' => ['gt' => 'a']]);
    }

    public function test_like_is_rejected_unless_enabled(): void
    {
        $this->expectExceptionMessage('operator [like] is not supported on field [brand]');

        $this->compile(['brand' => ['like' => 'nik']]);
    }

    public function test_like_compiles_to_contains_when_enabled(): void
    {
        config(['eav.search.engines.meilisearch.contains' => true]);

        $this->assertSame('brand CONTAINS "nik"', $this->compile(['brand' => ['like' => '%nik%']]));
        $this->assertSame('(brand CONTAINS "nik" OR brand CONTAINS "pum")', $this->compile(['brand' => ['like' => ['nik', 'pum']]]));
    }

    public function test_an_unknown_operator_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryException::class);

        $this->compile(['brand' => ['tree' => 1]]);
    }
}
