<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry\Concerns;

use Jurager\Eav\Registry\Concerns\CachesResolvedItems;
use PHPUnit\Framework\TestCase;

/** A minimal consumer, exposing the trait's private methods to exercise them directly. */
class CachesResolvedItemsFixture
{
    use CachesResolvedItems;

    public function resolve(string $partition, int|string $key, \Closure $query): mixed
    {
        return $this->resolved($partition, $key, $query);
    }

    public function forget(?string $partition = null): void
    {
        $this->forgetResolved($partition);
    }

    public static function flush(): void
    {
        static::flushResolved();
    }
}

class CachesResolvedItemsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CachesResolvedItemsFixture::flush();
    }

    public function test_resolved_returns_the_query_result_on_a_miss(): void
    {
        $fixture = new CachesResolvedItemsFixture();

        $this->assertSame('value', $fixture->resolve('p', 1, fn () => 'value'));
    }

    public function test_resolved_does_not_query_again_for_a_key_already_resolved(): void
    {
        $fixture = new CachesResolvedItemsFixture();
        $fixture->resolve('p', 1, fn () => 'value');

        $calls = 0;
        $result = $fixture->resolve('p', 1, function () use (&$calls) {
            $calls++;

            return 'other';
        });

        $this->assertSame('value', $result);
        $this->assertSame(0, $calls, 'the query must not run once the key is resolved.');
    }

    public function test_resolved_shares_a_hit_across_separate_instances(): void
    {
        $first = new CachesResolvedItemsFixture();
        $first->resolve('p', 1, fn () => 'value');

        $second = new CachesResolvedItemsFixture();
        $calls = 0;

        $result = $second->resolve('p', 1, function () use (&$calls) {
            $calls++;

            return 'other';
        });

        $this->assertSame('value', $result);
        $this->assertSame(0, $calls, 'the cache is static: a fresh instance must still see it.');
    }

    public function test_resolved_returns_null_for_a_missing_key_without_caching_null_as_a_value(): void
    {
        $fixture = new CachesResolvedItemsFixture();

        $this->assertNull($fixture->resolve('p', 1, fn () => null));
    }

    public function test_resolved_does_not_query_again_for_a_key_already_confirmed_missing(): void
    {
        $fixture = new CachesResolvedItemsFixture();
        $fixture->resolve('p', 1, fn () => null);

        $calls = 0;
        $result = $fixture->resolve('p', 1, function () use (&$calls) {
            $calls++;

            return 'value';
        });

        $this->assertNull($result);
        $this->assertSame(0, $calls, 'a confirmed miss must not be re-queried.');
    }

    public function test_partitions_are_isolated_from_each_other(): void
    {
        $fixture = new CachesResolvedItemsFixture();
        $fixture->resolve('p1', 1, fn () => 'p1-value');

        $this->assertSame('p2-value', $fixture->resolve('p2', 1, fn () => 'p2-value'), 'the same key in a different partition must not collide.');
    }

    public function test_forget_one_partition_clears_only_that_partition(): void
    {
        $fixture = new CachesResolvedItemsFixture();
        $fixture->resolve('p1', 1, fn () => 'value');
        $fixture->resolve('p2', 1, fn () => 'value');

        $fixture->forget('p1');

        $calls = 0;
        $fixture->resolve('p1', 1, function () use (&$calls) {
            $calls++;

            return 'value';
        });
        $fixture->resolve('p2', 1, function () use (&$calls) {
            $calls++;

            return 'value';
        });

        $this->assertSame(1, $calls, 'only the forgotten partition should be re-queried.');
    }

    public function test_forget_without_a_partition_clears_everything(): void
    {
        $fixture = new CachesResolvedItemsFixture();
        $fixture->resolve('p1', 1, fn () => 'value');
        $fixture->resolve('p2', 1, fn () => 'value');

        $fixture->forget();

        $calls = 0;
        $fixture->resolve('p1', 1, function () use (&$calls) {
            $calls++;

            return 'value';
        });
        $fixture->resolve('p2', 1, function () use (&$calls) {
            $calls++;

            return 'value';
        });

        $this->assertSame(2, $calls);
    }

    public function test_flush_clears_every_partition_for_every_instance(): void
    {
        $first = new CachesResolvedItemsFixture();
        $first->resolve('p', 1, fn () => 'value');

        CachesResolvedItemsFixture::flush();

        $second = new CachesResolvedItemsFixture();
        $calls = 0;

        $second->resolve('p', 1, function () use (&$calls) {
            $calls++;

            return 'value';
        });

        $this->assertSame(1, $calls);
    }
}
