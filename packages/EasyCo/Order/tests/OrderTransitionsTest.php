<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\InvalidOrderTransitionException;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Order's five transition mutators and the matrix they obey — the domain
 * half of order-lifecycle-design.md §5.1.
 *
 * WHY THE SEVEN MOVES ARE WRITTEN OUT A SECOND TIME: OrderStatusTest
 * already walks the enum against §2.1's table, and that test would still
 * pass if a mutator bypassed the enum altogether (a hard-coded assignment,
 * a hand-written `if` on a status). So every (status, mutator) pair of the
 * 6 x 5 grid is asked all three ways here — the hand-written table below,
 * OrderStatus::canTransitionTo(), and what the aggregate actually does
 * when the mutator is called — and the three must agree.
 *
 * THE SURFACE ITSELF IS ASSERTED, NOT ASSUMED: §5.1's rule is as much
 * about the moves that have no method. There is deliberately no `place()`
 * (that is what construction is), no `return()` (a return that leaves
 * units outstanding moves no status), and nothing that moves a status
 * backwards — so the public method list is compared against a written-out
 * one, and the shared transitionTo() is asserted private.
 *
 * Standalone suite, no Laravel: these tests need the aggregate and nothing
 * else (packages/EasyCo/Order/phpunit.xml).
 */
final class OrderTransitionsTest extends TestCase
{
    /**
     * §5.1's five methods, and the status each one moves an order to.
     *
     * @var array<string, string>
     */
    private const MUTATORS = [
        'confirm' => 'confirmed',
        'ship' => 'shipped',
        'deliver' => 'delivered',
        'cancel' => 'cancelled',
        'refund' => 'refunded',
    ];

    /**
     * §2.1's seven rows by hand: the status the order stands in, the one
     * mutator §5.1 names for that move, and the status it ends in.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const LEGAL_MOVES = [
        ['placed', 'confirm', 'confirmed'],
        ['placed', 'cancel', 'cancelled'],
        ['confirmed', 'ship', 'shipped'],
        ['confirmed', 'cancel', 'cancelled'],
        ['shipped', 'deliver', 'delivered'],
        ['shipped', 'cancel', 'cancelled'],
        ['delivered', 'refund', 'refunded'],
    ];

    /**
     * Every public method Order has, written out so that a new one has to be
     * added here on purpose. assertSame() below sorts both sides, so this
     * order is for reading, not comparing.
     *
     * @var list<string>
     */
    private const PUBLIC_METHODS = [
        'id', 'assignId',
        'clientId', 'accountId', 'transactionId', 'email',
        'currency', 'subtotal', 'discount', 'total',
        // shipping stage 2 — order-level shipping accessors, none touches $status.
        'shipping', 'shippingMethodName', 'shippingMethodCode',
        'appliedPromotionCode', 'status',
        'confirm', 'ship', 'deliver', 'cancel', 'refund',
        'placedAt', 'addressId', 'deliveryType', 'recipientName', 'phone',
        'country', 'city', 'postalCode', 'addressLine1', 'addressLine2',
        'carrierCode', 'pickupPointReference', 'settlement',
        'create', 'reconstituteFromStorage',
        // order-editing-design.md §2, stage 2 — the two edit mutators.
        // Neither changes $status (§2's own "status itself is untouched by
        // either mutator") — both have required parameters, so
        // test_no_other_public_method_changes_the_status() below skips them
        // rather than needing a MUTATORS entry.
        'reviseTotals', 'reviseDelivery',
        // order-editing-design.md §2.2, stage 3b (D1) — the edit-revision
        // counter's accessor and its one increment. Neither touches $status.
        'editRevision', 'bumpEditRevision',
    ];

    /** The street-address fixture OrderTest uses, with the status under test. */
    private function order(OrderStatus $status): Order
    {
        return Order::create(
            clientId: 'client-1',
            transactionId: 'transaction-1',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            status: $status,
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
        );
    }

    /**
     * Every accessor's answer except status(), as scalar values, so two
     * readings can be compared byte for byte.
     *
     * @return array<string, mixed>
     */
    private function everythingButStatus(Order $order): array
    {
        return [
            'id' => $order->id(),
            'clientId' => $order->clientId(),
            'accountId' => $order->accountId(),
            'transactionId' => $order->transactionId(),
            'email' => $order->email(),
            'currency' => $order->currency()->code(),
            'subtotal' => $order->subtotal()->minorValue(),
            'discount' => $order->discount()->minorValue(),
            'total' => $order->total()->minorValue(),
            'appliedPromotionCode' => $order->appliedPromotionCode(),
            'placedAt' => $order->placedAt()->format('Y-m-d H:i:s'),
            'addressId' => $order->addressId(),
            'deliveryType' => $order->deliveryType()->value,
            'recipientName' => $order->recipientName(),
            'phone' => $order->phone(),
            'country' => $order->country(),
            'city' => $order->city(),
            'postalCode' => $order->postalCode(),
            'addressLine1' => $order->addressLine1(),
            'addressLine2' => $order->addressLine2(),
            'carrierCode' => $order->carrierCode(),
            'pickupPointReference' => $order->pickupPointReference(),
            'settlement' => $order->settlement(),
        ];
    }

    /**
     * §2.1's table again, as (from, to) pairs, derived from LEGAL_MOVES so
     * the two cannot disagree.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function legalPairs(): array
    {
        return array_map(
            static fn (array $move): array => [$move[0], $move[2]],
            self::LEGAL_MOVES,
        );
    }

    /**
     * (a) and (h): each row of §2.1's table is performed by the mutator
     * §5.1 names for it, from the status it may start in — and every other
     * accessor answers exactly what it answered before the call.
     */
    public function test_each_mutator_performs_its_own_move_and_writes_nothing_else(): void
    {
        foreach (self::LEGAL_MOVES as [$from, $mutator, $to]) {
            $order = $this->order(OrderStatus::from($from));
            $before = $this->everythingButStatus($order);

            $order->{$mutator}();

            $this->assertSame(OrderStatus::from($to), $order->status(), "{$mutator}() should move {$from} to {$to}.");
            $this->assertSame($before, $this->everythingButStatus($order), "{$mutator}() wrote a field other than the status.");
        }
    }

    /**
     * (b) and (e): every one of the 30 (status, mutator) pairs that is not
     * one of §2.1's seven is refused, names the pair it refused, and leaves
     * the order exactly where it stood. The same-status pairs are the
     * "already" refusal; the rest are the plain one.
     */
    public function test_every_illegal_move_is_refused_with_its_pair_and_changes_nothing(): void
    {
        $refused = 0;

        foreach (OrderStatus::cases() as $from) {
            foreach (self::MUTATORS as $mutator => $target) {
                if (in_array([$from->value, $target], $this->legalPairs(), true)) {
                    continue;
                }

                $order = $this->order($from);
                $refused++;

                try {
                    $order->{$mutator}();
                    $this->fail("{$mutator}() from {$from->value} is not a legal move and must be refused.");
                } catch (InvalidOrderTransitionException $e) {
                    $this->assertSame($from, $e->from(), "{$mutator}() from {$from->value} named the wrong from.");
                    $this->assertSame(OrderStatus::from($target), $e->to(), "{$mutator}() from {$from->value} named the wrong to.");
                }

                $this->assertSame($from, $order->status(), "{$mutator}() from {$from->value} changed the status before refusing.");
            }
        }

        $this->assertSame(23, $refused, 'Six statuses x five mutators is 30 pairs; §2.1 makes seven legal, so 23 must be refused.');
    }

    /**
     * (c): the grid read three ways at once — the hand-written table,
     * OrderStatus::canTransitionTo(), and what the aggregate actually does
     * when the mutator is called. A mutator that bypassed the enum, or an
     * enum edit the mutators ignore, fails here.
     */
    public function test_the_grid_agrees_with_the_enum_and_with_the_hand_written_table(): void
    {
        $legalPairs = $this->legalPairs();
        $performed = [];
        $refused = [];

        foreach (OrderStatus::cases() as $from) {
            foreach (self::MUTATORS as $mutator => $target) {
                $to = OrderStatus::from($target);

                $this->assertSame(
                    in_array([$from->value, $target], $legalPairs, true),
                    $from->canTransitionTo($to),
                    "canTransitionTo() and §2.1's hand-written table disagree on {$from->value} -> {$target}.",
                );

                $order = $this->order($from);

                try {
                    $order->{$mutator}();
                    $performed["{$from->value} -> {$target}"] = $order->status()->value;
                } catch (InvalidOrderTransitionException) {
                    $refused[] = "{$from->value} -> {$target}";
                }
            }
        }

        $this->assertSame([
            'placed -> confirmed' => 'confirmed',
            'placed -> cancelled' => 'cancelled',
            'confirmed -> shipped' => 'shipped',
            'confirmed -> cancelled' => 'cancelled',
            'shipped -> delivered' => 'delivered',
            'shipped -> cancelled' => 'cancelled',
            'delivered -> refunded' => 'refunded',
        ], $performed);

        $this->assertCount(23, $refused);
    }

    /**
     * (d): refund() — the one move no operator makes by clicking — is
     * reachable from delivered and from nothing else, terminal statuses
     * included.
     */
    public function test_refund_is_reachable_from_nothing_but_delivered(): void
    {
        $reachable = [];
        $refused = [];

        foreach (OrderStatus::cases() as $from) {
            $order = $this->order($from);

            try {
                $order->refund();
                $reachable[] = $from->value;
                $this->assertSame(OrderStatus::REFUNDED, $order->status());
            } catch (InvalidOrderTransitionException $e) {
                $refused[] = $from->value;
                $this->assertSame($from, $e->from());
                $this->assertSame(OrderStatus::REFUNDED, $e->to());
                $this->assertSame($from, $order->status());
            }
        }

        $this->assertSame(['delivered'], $reachable);
        $this->assertSame(['placed', 'confirmed', 'shipped', 'cancelled', 'refunded'], $refused);
    }

    /**
     * (e): the two terminal statuses have no way out — the same fact
     * OrderStatus::isTerminal() states — and every refusal names the pair.
     */
    public function test_the_two_terminal_statuses_refuse_all_five_mutators(): void
    {
        foreach ([OrderStatus::CANCELLED, OrderStatus::REFUNDED] as $terminal) {
            $this->assertTrue($terminal->isTerminal(), "{$terminal->value} must be terminal for this test to mean anything.");

            foreach (self::MUTATORS as $mutator => $target) {
                $order = $this->order($terminal);

                try {
                    $order->{$mutator}();
                    $this->fail("{$mutator}() must be refused on a terminal ({$terminal->value}) order.");
                } catch (InvalidOrderTransitionException $e) {
                    $this->assertSame($terminal, $e->from());
                    $this->assertSame(OrderStatus::from($target), $e->to());
                }

                $this->assertSame($terminal, $order->status());
            }
        }
    }

    /**
     * (f): a call for the status the order is already in gets the
     * "already" refusal, not the generic one — the pair it carries is that
     * status twice, and the message says which status.
     */
    public function test_a_same_status_call_is_refused_as_already_in_that_status(): void
    {
        foreach (self::MUTATORS as $mutator => $target) {
            $status = OrderStatus::from($target);
            $order = $this->order($status);

            try {
                $order->{$mutator}();
                $this->fail("{$mutator}() on an order that is already {$target} must be refused.");
            } catch (InvalidOrderTransitionException $e) {
                $this->assertSame($status, $e->from());
                $this->assertSame($status, $e->to());
                $this->assertStringContainsString("already \"{$target}\"", $e->getMessage());
                $this->assertStringNotContainsString('not one of the legal transitions', $e->getMessage());
            }

            $this->assertSame($status, $order->status());
        }
    }

    /**
     * (h) along the whole happy path: four moves later, every field a
     * transition may not touch still answers exactly what it answered at
     * placement.
     */
    public function test_the_whole_lifecycle_leaves_every_other_field_untouched(): void
    {
        $order = $this->order(OrderStatus::PLACED);
        $atPlacement = $this->everythingButStatus($order);

        $order->confirm();
        $order->ship();
        $order->deliver();
        $order->refund();

        $this->assertSame(OrderStatus::REFUNDED, $order->status());
        $this->assertSame($atPlacement, $this->everythingButStatus($order));
    }

    /**
     * (i): both refusals carry the pair as usable values, and say which
     * statuses they mean in their message.
     */
    public function test_the_exception_carries_the_pair_it_refused(): void
    {
        $order = $this->order(OrderStatus::PLACED);

        try {
            $order->deliver();
            $this->fail('placed -> delivered is not one of §2.1\'s seven moves.');
        } catch (InvalidOrderTransitionException $e) {
            $this->assertSame(OrderStatus::PLACED, $e->from());
            $this->assertSame(OrderStatus::DELIVERED, $e->to());
            $this->assertStringContainsString('placed', $e->getMessage());
            $this->assertStringContainsString('delivered', $e->getMessage());
        }

        $confirmed = $this->order(OrderStatus::CONFIRMED);

        try {
            $confirmed->confirm();
            $this->fail('An order that is already confirmed cannot be confirmed again.');
        } catch (InvalidOrderTransitionException $e) {
            $this->assertSame(OrderStatus::CONFIRMED, $e->from());
            $this->assertSame(OrderStatus::CONFIRMED, $e->to());
            $this->assertStringContainsString('already', $e->getMessage());
        }
    }

    /**
     * (g): the public surface is exactly the methods this class is meant to
     * have. A new public method — including a second way to change a status
     * — has to be added to PUBLIC_METHODS on purpose, and the deliberate
     * absences are asserted too.
     */
    public function test_the_public_surface_is_exactly_the_five_mutators_and_the_rest(): void
    {
        $actual = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Order::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $expected = self::PUBLIC_METHODS;

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'Order\'s public surface changed — §5.1\'s five mutators are the only ways a status moves.');

        // The moves that deliberately have no method at all: "placed" is where
        // an order starts rather than a move into it, a return that leaves
        // units outstanding moves no status, and nothing moves a status
        // backwards.
        $reflection = new ReflectionClass(Order::class);

        foreach (['place', 'return', 'recordReturn', 'revert', 'rollback', 'unconfirm', 'unship', 'undeliver', 'uncancel', 'unrefund', 'setStatus'] as $forbidden) {
            $this->assertFalse($reflection->hasMethod($forbidden), "Order must have no {$forbidden}() — there is no such move.");
        }
    }

    /**
     * (g): the one place a status changes is private, so no caller can move
     * a status by any name other than §5.1's five.
     */
    public function test_the_shared_transition_method_is_private(): void
    {
        $transitionTo = new ReflectionMethod(Order::class, 'transitionTo');

        $this->assertTrue($transitionTo->isPrivate());
        $this->assertSame(1, $transitionTo->getNumberOfParameters());
        $this->assertSame('EasyCo\\Order\\Enums\\OrderStatus', $transitionTo->getParameters()[0]->getType()->getName());
    }

    /**
     * (g): no public method other than the five mutators changes a status —
     * asserted by calling every one of them that can be called without
     * arguments, assignId() included, on an order that has one.
     */
    public function test_no_other_public_method_changes_the_status(): void
    {
        $other = $this->order(OrderStatus::SHIPPED);

        $other->assignId('42');

        $this->assertSame('42', $other->id());
        $this->assertSame(OrderStatus::SHIPPED, $other->status(), 'assignId() must not touch the status.');

        foreach ((new ReflectionClass(Order::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if (array_key_exists($method->getName(), self::MUTATORS)) {
                continue;   // Covered above and below; these five DO change it.
            }

            $order = $this->order(OrderStatus::SHIPPED);

            $method->invoke($order);

            $this->assertSame(OrderStatus::SHIPPED, $order->status(), "{$method->getName()}() changed the status.");
        }
    }
}
