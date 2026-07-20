<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Search;

use Jurager\Eav\Search\MeilisearchFilterCompiler;
use Jurager\Eav\Tests\TestCase;
use Jurager\Filterable\Parsing\FilterParser;
use Jurager\Filterable\Support\ParsedFilters;

class MeilisearchFilterCompilerTest extends TestCase
{
    private MeilisearchFilterCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new MeilisearchFilterCompiler();
    }

    private function parse(array $raw): ParsedFilters
    {
        return (new FilterParser())->parse($raw, []);
    }

    /** @return \Closure(string): ?string */
    private function resolver(array $map): \Closure
    {
        return fn (string $key): ?string => $map[$key] ?? null;
    }

    // -----------------------------------------------------------------------
    // compile()
    // -----------------------------------------------------------------------

    public function test_compile_resolves_a_known_key(): void
    {
        $result = $this->compiler->compile($this->parse(['id' => ['in' => '1,2,3']]), $this->resolver(['id' => 'id']));

        $this->assertSame('id IN [1, 2, 3]', $result);
    }

    public function test_compile_drops_an_unresolvable_key(): void
    {
        $result = $this->compiler->compile($this->parse(['id' => ['in' => '1,2,3']]), $this->resolver([]));

        $this->assertNull($result);
    }

    public function test_compile_drops_only_the_unresolvable_key_and_keeps_the_rest(): void
    {
        $result = $this->compiler->compile(
            $this->parse(['id' => ['in' => '1,2'], 'unknown' => ['eq' => 'x']]),
            $this->resolver(['id' => 'id']),
        );

        $this->assertSame('id IN [1, 2]', $result);
    }

    // -----------------------------------------------------------------------
    // unresolved()
    // -----------------------------------------------------------------------

    public function test_unresolved_is_empty_when_every_key_resolves(): void
    {
        $result = $this->compiler->unresolved(
            $this->parse(['id' => ['in' => '1,2'], 'category_ids' => ['eq' => 5]]),
            $this->resolver(['id' => 'id', 'category_ids' => 'category_ids']),
        );

        $this->assertSame([], $result);
    }

    public function test_unresolved_returns_the_keys_that_dont_resolve(): void
    {
        $filter = ['id' => ['in' => '1,2'], 'sku' => ['eq' => 'ABC']];

        $result = $this->compiler->unresolved($this->parse($filter), $this->resolver(['id' => 'id']));

        $this->assertSame(['sku' => ['eq' => 'ABC']], $result);
    }

    public function test_unresolved_keeps_an_or_group_whole_when_any_member_is_unresolved(): void
    {
        $filter = [
            'or' => [
                ['id' => ['in' => '1,2']],
                ['sku' => ['eq' => 'ABC']],
            ],
        ];

        $result = $this->compiler->unresolved($this->parse($filter), $this->resolver(['id' => 'id']));

        $this->assertSame($filter, $result);
    }

    public function test_unresolved_drops_an_or_group_when_every_member_resolves(): void
    {
        $filter = [
            'or' => [
                ['id' => ['in' => '1,2']],
                ['category_ids' => ['eq' => 5]],
            ],
        ];

        $result = $this->compiler->unresolved($this->parse($filter), $this->resolver(['id' => 'id', 'category_ids' => 'category_ids']));

        $this->assertSame([], $result);
    }

    public function test_unresolved_and_compile_agree_on_the_same_filter(): void
    {
        $filter = ['id' => ['in' => '1,2'], 'sku' => ['eq' => 'ABC']];
        $resolve = $this->resolver(['id' => 'id']);

        $compiled = $this->compiler->compile($this->parse($filter), $resolve);
        $leftover = $this->compiler->unresolved($this->parse($filter), $resolve);

        $this->assertSame('id IN [1, 2]', $compiled);
        $this->assertSame(['sku' => ['eq' => 'ABC']], $leftover);
    }
}
