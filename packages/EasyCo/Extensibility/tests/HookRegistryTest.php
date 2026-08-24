<?php

namespace EasyCo\Extensibility\Tests;

use EasyCo\Extensibility\HookRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HookRegistryTest extends TestCase
{
    private HookRegistry $hooks;

    protected function setUp(): void
    {
        $this->hooks = new HookRegistry();
    }

    public function test_filters_chain_in_priority_order_each_receiving_the_previous_return_value(): void
    {
        $this->hooks->addFilter('price', fn (int $value) => $value + 1, priority: 20);
        $this->hooks->addFilter('price', fn (int $value) => $value * 2, priority: 10);

        // priority 10 runs first: 5 * 2 = 10, then priority 20 sees that result: 10 + 1 = 11
        $result = $this->hooks->applyFilters('price', 5);

        $this->assertSame(11, $result);
    }

    public function test_filters_with_the_same_priority_run_in_registration_order(): void
    {
        $order = [];
        $this->hooks->addFilter('name', function (string $value) use (&$order) {
            $order[] = 'first';

            return $value.'-first';
        }, priority: 10);
        $this->hooks->addFilter('name', function (string $value) use (&$order) {
            $order[] = 'second';

            return $value.'-second';
        }, priority: 10);

        $result = $this->hooks->applyFilters('name', 'base');

        $this->assertSame(['first', 'second'], $order);
        $this->assertSame('base-first-second', $result);
    }

    public function test_context_args_reach_every_filter_callback_unchanged(): void
    {
        $seenContexts = [];
        $recordContext = function (int $value, string $currency, int $quantity) use (&$seenContexts) {
            $seenContexts[] = [$currency, $quantity];

            return $value + 1;
        };
        $this->hooks->addFilter('price', $recordContext, priority: 10);
        $this->hooks->addFilter('price', $recordContext, priority: 20);

        $result = $this->hooks->applyFilters('price', 100, 'EUR', 3);

        $this->assertSame(102, $result);
        $this->assertSame([['EUR', 3], ['EUR', 3]], $seenContexts, 'context args must reach every callback unchanged, not threaded like $value');
    }

    public function test_a_hook_with_no_registered_filters_returns_the_input_value_unchanged(): void
    {
        $result = $this->hooks->applyFilters('nonexistent.hook', 'unchanged-value');

        $this->assertSame('unchanged-value', $result);
    }

    public function test_multiple_actions_all_fire_in_priority_order_each_receiving_the_same_args(): void
    {
        $calls = [];
        $this->hooks->addAction('order.created', function (string $orderId, int $total) use (&$calls) {
            $calls[] = ['late', $orderId, $total];
        }, priority: 20);
        $this->hooks->addAction('order.created', function (string $orderId, int $total) use (&$calls) {
            $calls[] = ['early', $orderId, $total];
        }, priority: 10);

        $this->hooks->doAction('order.created', 'ORD-1', 4999);

        $this->assertSame([
            ['early', 'ORD-1', 4999],
            ['late', 'ORD-1', 4999],
        ], $calls);
    }

    public function test_an_exception_from_one_action_propagates_and_stops_subsequent_callbacks(): void
    {
        $calls = [];
        $this->hooks->addAction('order.created', function () use (&$calls) {
            $calls[] = 'first';
        }, priority: 10);
        $this->hooks->addAction('order.created', function () {
            throw new RuntimeException('listener blew up');
        }, priority: 20);
        $this->hooks->addAction('order.created', function () use (&$calls) {
            $calls[] = 'third';
        }, priority: 30);

        try {
            $this->hooks->doAction('order.created');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('listener blew up', $e->getMessage());
        }

        $this->assertSame(['first'], $calls, 'the callback registered after the throwing one must never run');
    }

    public function test_an_exception_from_one_filter_propagates_immediately_without_falling_back(): void
    {
        $this->hooks->addFilter('price', fn (int $value) => $value + 1, priority: 10);
        $this->hooks->addFilter('price', function (int $value) {
            throw new RuntimeException('filter blew up');
        }, priority: 20);
        $this->hooks->addFilter('price', fn (int $value) => $value * 100, priority: 30);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('filter blew up');

        $this->hooks->applyFilters('price', 5);
    }

    public function test_clear_with_a_hook_name_removes_only_that_hooks_listeners(): void
    {
        $this->hooks->addAction('a', fn () => null);
        $this->hooks->addFilter('a', fn ($v) => $v);
        $this->hooks->addAction('b', fn () => null);

        $this->hooks->clear('a');

        $this->assertFalse($this->hooks->hasListeners('a'));
        $this->assertTrue($this->hooks->hasListeners('b'));
    }

    public function test_clear_with_no_argument_removes_everything(): void
    {
        $this->hooks->addAction('a', fn () => null);
        $this->hooks->addFilter('b', fn ($v) => $v);

        $this->hooks->clear();

        $this->assertFalse($this->hooks->hasListeners('a'));
        $this->assertFalse($this->hooks->hasListeners('b'));
    }

    public function test_has_listeners_reflects_registration_state(): void
    {
        $this->assertFalse($this->hooks->hasListeners('quiet.hook'));

        $this->hooks->addAction('quiet.hook', fn () => null);
        $this->assertTrue($this->hooks->hasListeners('quiet.hook'));

        $this->hooks->clear('quiet.hook');
        $this->assertFalse($this->hooks->hasListeners('quiet.hook'));

        $this->hooks->addFilter('filter.hook', fn ($v) => $v);
        $this->assertTrue($this->hooks->hasListeners('filter.hook'), 'a filter alone must also count as having listeners');
    }
}
