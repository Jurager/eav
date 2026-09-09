<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Unit\Registry\Concerns;

use Jurager\Eav\Registry\Concerns\TracksTableChanges;
use PHPUnit\Framework\TestCase;

/** A minimal consumer, exposing the trait's private methods to exercise them directly. */
class TracksTableChangesFixture
{
    use TracksTableChanges;

    public function changed(string $scope, \Closure $stamp): bool
    {
        return $this->tableChanged($stamp, $scope);
    }

    public function markFresh(string $scope, \Closure $stamp): void
    {
        $this->markTableFresh($stamp, $scope);
    }

    public function forget(?string $scope = null): void
    {
        $this->forgetTableChange($scope);
    }

    public static function flush(): void
    {
        static::flushTableChanges();
    }

    /** Exercises the trait with no scope argument at all — the shape an unscoped registry uses. */
    public function changedUnscoped(\Closure $stamp): bool
    {
        return $this->tableChanged($stamp);
    }
}

class TracksTableChangesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TracksTableChangesFixture::flush();
    }

    public function test_changed_is_true_the_first_time_a_scope_is_checked(): void
    {
        $fixture = new TracksTableChangesFixture();

        $this->assertTrue($fixture->changed('a', fn () => 'v1'));
    }

    public function test_changed_is_false_on_a_second_check_within_the_same_instance_regardless_of_the_stamp(): void
    {
        $fixture = new TracksTableChangesFixture();

        $fixture->changed('a', fn () => 'v1');

        // Stamp genuinely differs now, but the scope was already checked this "request".
        $this->assertFalse($fixture->changed('a', fn () => 'v2'));
    }

    public function test_changed_reflects_a_stamp_difference_recorded_by_an_earlier_instance(): void
    {
        $first = new TracksTableChangesFixture();
        $first->changed('a', fn () => 'v1');

        // A fresh instance simulates the next request: the checked flag resets, the recorded
        // stamp (static) does not.
        $second = new TracksTableChangesFixture();

        $this->assertTrue($second->changed('a', fn () => 'v2'));
    }

    public function test_changed_is_false_on_a_fresh_instance_when_the_stamp_did_not_move(): void
    {
        $first = new TracksTableChangesFixture();
        $first->changed('a', fn () => 'v1');

        $second = new TracksTableChangesFixture();

        $this->assertFalse($second->changed('a', fn () => 'v1'));
    }

    public function test_a_real_check_refreshes_the_recorded_stamp_even_when_only_checked_ever_ran(): void
    {
        // Regression: a scope whose staleness is ONLY ever verified through changed() (never
        // through markFresh()) must still keep the recorded stamp current — otherwise the next
        // instance compares against a stamp that was never updated and reports "changed" forever.
        $first = new TracksTableChangesFixture();
        $first->changed('a', fn () => 'v1');

        $second = new TracksTableChangesFixture();
        $this->assertTrue($second->changed('a', fn () => 'v2'));

        $third = new TracksTableChangesFixture();
        $this->assertFalse($third->changed('a', fn () => 'v2'));
    }

    public function test_scopes_are_tracked_independently(): void
    {
        $fixture = new TracksTableChangesFixture();

        $fixture->changed('a', fn () => 'v1');

        $this->assertTrue($fixture->changed('b', fn () => 'v1'), 'a different scope must still get its own first check.');
    }

    public function test_mark_fresh_records_the_stamp_without_reporting_a_change(): void
    {
        $fixture = new TracksTableChangesFixture();
        $fixture->markFresh('a', fn () => 'v1');

        $second = new TracksTableChangesFixture();

        $this->assertFalse($second->changed('a', fn () => 'v1'));
    }

    public function test_forget_one_scope_makes_the_next_check_true_again(): void
    {
        $fixture = new TracksTableChangesFixture();
        $fixture->changed('a', fn () => 'v1');
        $fixture->changed('b', fn () => 'v1');

        $fixture->forget('a');

        $this->assertTrue($fixture->changed('a', fn () => 'v1'), 'forgotten scope must re-check even within the same instance.');
        $this->assertFalse($fixture->changed('b', fn () => 'v1'), 'untouched scope must be unaffected.');
    }

    public function test_forget_without_a_scope_clears_every_scope(): void
    {
        $fixture = new TracksTableChangesFixture();
        $fixture->changed('a', fn () => 'v1');
        $fixture->changed('b', fn () => 'v1');

        $fixture->forget();

        $this->assertTrue($fixture->changed('a', fn () => 'v1'));
        $this->assertTrue($fixture->changed('b', fn () => 'v1'));
    }

    public function test_scope_is_optional_for_a_registry_that_has_only_one_implicit_scope(): void
    {
        $first = new TracksTableChangesFixture();
        $this->assertTrue($first->changedUnscoped(fn () => 'v1'));

        $second = new TracksTableChangesFixture();
        $this->assertFalse($second->changedUnscoped(fn () => 'v1'), 'unchanged stamp on a fresh instance must not report a change.');

        $third = new TracksTableChangesFixture();
        $this->assertTrue($third->changedUnscoped(fn () => 'v2'), 'a genuinely different stamp must still be caught.');
    }

    public function test_flush_clears_every_scope_for_every_instance(): void
    {
        $first = new TracksTableChangesFixture();
        $first->changed('a', fn () => 'v1');

        TracksTableChangesFixture::flush();

        $second = new TracksTableChangesFixture();

        $this->assertTrue($second->changed('a', fn () => 'v1'));
    }
}
