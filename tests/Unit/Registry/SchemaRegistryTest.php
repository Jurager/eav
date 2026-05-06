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

        $this->registry = new SchemaRegistry();
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

    public function test_different_keys_are_cached_independently(): void
    {
        $result1 = $this->registry->resolve('product:default', fn () => collect(['product']));
        $result2 = $this->registry->resolve('category:default', fn () => collect(['category']));

        $this->assertSame(['product'], $result1->all());
        $this->assertSame(['category'], $result2->all());
    }
}
