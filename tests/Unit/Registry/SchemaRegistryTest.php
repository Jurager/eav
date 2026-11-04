<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry;

use Illuminate\Support\Collection;
use Jurager\Eav\Registry\SchemaRegistry;
use Jurager\Eav\Tests\TestCase;

class SchemaRegistryTest extends TestCase
{
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new SchemaRegistry;
    }

    public function test_resolve_calls_loader_on_first_access(): void
    {
        $called = 0;

        $this->registry->resolve('product:default', function () use (&$called) {
            $called++;

            return collect(['attr1']);
        });

        $this->assertSame(1, $called);
    }

    public function test_resolve_returns_cached_result_on_second_call(): void
    {
        $calls = 0;

        $this->registry->resolve('product:default', function () use (&$calls) {
            $calls++;

            return collect(['attr1']);
        });

        $this->registry->resolve('product:default', function () use (&$calls) {
            $calls++;

            return collect(['attr2']);
        });

        $this->assertSame(1, $calls);
    }

    public function test_resolve_returns_the_loaded_collection(): void
    {
        $result = $this->registry->resolve('product:default', fn () => collect(['a', 'b']));

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(['a', 'b'], $result->all());
    }

    public function test_forget_by_entity_type_removes_matching_keys(): void
    {
        $calls = 0;

        $this->registry->resolve('product:default', function () use (&$calls) {
            $calls++;

            return collect();
        });

        $this->registry->forget('product');

        $this->registry->resolve('product:default', function () use (&$calls) {
            $calls++;

            return collect();
        });

        $this->assertSame(2, $calls);
    }

    public function test_forget_by_entity_type_does_not_remove_other_entities(): void
    {
        $categoryCalls = 0;

        $this->registry->resolve('product:default', fn () => collect());
        $this->registry->resolve('category:default', function () use (&$categoryCalls) {
            $categoryCalls++;

            return collect();
        });

        $this->registry->forget('product');

        $this->registry->resolve('category:default', function () use (&$categoryCalls) {
            $categoryCalls++;

            return collect();
        });

        $this->assertSame(1, $categoryCalls);
    }

    public function test_forget_null_clears_all_schemas(): void
    {
        $calls = 0;

        $loader = function () use (&$calls) {
            $calls++;

            return collect();
        };

        $this->registry->resolve('product:default', $loader);
        $this->registry->resolve('category:default', $loader);

        $this->registry->forget(null);

        $this->registry->resolve('product:default', $loader);
        $this->registry->resolve('category:default', $loader);

        $this->assertSame(4, $calls);
    }

    public function test_resolve_scopes_loads_only_the_ids_not_cached_and_remembers_the_ones_it_found_nothing_for(): void
    {
        $requested = [];
        $loader = function (array $ids) use (&$requested): array {
            $requested[] = $ids;

            return array_intersect_key([1 => [10, 11], 2 => [20]], array_flip($ids));
        };

        $first = $this->registry->resolveScopes('Category', [1, 9], $loader);
        $second = $this->registry->resolveScopes('Category', [9, 1, 2], $loader);
        $third = $this->registry->resolveScopes('Category', [2, 9], $loader);

        $this->assertSame([[1, 9], [2]], $requested);
        $this->assertSame([1 => [10, 11], 9 => []], $first);
        $this->assertSame([1 => [10, 11], 9 => [], 2 => [20]], $second);
        $this->assertSame([9 => [], 2 => [20]], $third);
    }

    public function test_resolve_scopes_takes_the_ids_alongside_only_when_one_is_missing_and_skips_the_cached_ones(): void
    {
        $requested = [];
        $asked = 0;
        $loader = function (array $ids) use (&$requested): array {
            $requested[] = $ids;

            return array_fill_keys($ids, [1]);
        };
        $alongside = function () use (&$asked): array {
            $asked++;

            return [1, 2, 3];
        };

        $this->registry->resolveScopes('Category', [1], $loader);
        $this->registry->resolveScopes('Category', [1], $loader, $alongside);
        $resolved = $this->registry->resolveScopes('Category', [4], $loader, $alongside);
        $this->registry->resolveScopes('Category', [2, 3], $loader, $alongside);

        $this->assertSame([[1], [4, 2, 3]], $requested);
        $this->assertSame(1, $asked);
        $this->assertSame([4 => [1]], $resolved);
    }

    public function test_resolve_scopes_are_cached_per_scope_model(): void
    {
        $calls = 0;
        $loader = function (array $ids) use (&$calls): array {
            $calls++;

            return [];
        };

        $this->registry->resolveScopes('Category', [1], $loader);
        $this->registry->resolveScopes('EntryType', [1], $loader);

        $this->assertSame(2, $calls);
    }

    public function test_forgetting_any_entity_type_drops_resolved_scopes(): void
    {
        $calls = 0;
        $loader = function (array $ids) use (&$calls): array {
            $calls++;

            return [];
        };

        $this->registry->resolveScopes('Category', [1], $loader);
        $this->registry->forget('product');
        $this->registry->resolveScopes('Category', [1], $loader);
        $this->registry->forget();
        $this->registry->resolveScopes('Category', [1], $loader);

        $this->assertSame(3, $calls);
    }
}
