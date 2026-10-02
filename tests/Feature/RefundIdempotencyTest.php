<?php

namespace Tests\Feature;

use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\OrderStatusChanger;
use App\Services\RefundOperationFingerprint;
use App\Services\RefundRequest;
use EasyCo\Extensibility\Hook;
use EasyCo\Payment\Enums\RefundChannel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R1b (shipping-domain-design.md §7.2.3, owner decision R1a-4): a repeat
 * of the same operation key with the same contents is one cancel/return, not
 * two; the same key with other contents is refused.
 */
class RefundIdempotencyTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    private function returning(string $lineId, int $quantity, bool $restock = true): array
    {
        return [['originatingSaleLineId' => $lineId, 'quantityReturned' => $quantity, 'restock' => $restock]];
    }

    public function test_the_same_key_and_the_same_payload_creates_one_return_and_one_refund(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $request = fn () => new RefundRequest(operationKey: 'dialog-open-1');

        $first = $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), 'wrong size', $request());
        $second = $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), 'wrong size', $request());

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($second->wasReplay(), 'the second submission is recognised as a repeat');
        $this->assertSame($first->refund()->id(), $second->refund()->id(), 'and returns the FIRST result');

        $this->assertSame(12, $this->stockOf($order['variationIds'][0]), 'the units were returned once');
        $this->assertCount(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->get());
        $this->assertCount(1, $this->refundsOf($order['payment']));
        $this->assertSame(['returned', 'refund_owed'], $this->eventTypesOf($order['orderId']), 'and the history has one entry each');
    }

    public function test_a_repeated_full_cancel_replays_instead_of_being_refused_as_already_cancelled(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        $first = $this->changer()->cancel($order['orderId'], $this->at(), 'changed mind', [], new RefundRequest(operationKey: 'cancel-key'));
        $second = $this->changer()->cancel($order['orderId'], $this->at(), 'changed mind', [], new RefundRequest(operationKey: 'cancel-key'));

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($second->wasReplay());
        $this->assertSame($first->refund()->id(), $second->refund()->id());
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]));
        $this->assertCount(1, $this->refundsOf($order['payment']));
        $this->assertSame(['returned', 'status_changed', 'refund_owed'], $this->eventTypesOf($order['orderId']));
    }

    public function test_a_replay_fires_no_hook_again(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);
        $fired = 0;
        Hook::action('order.returned', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(operationKey: 'k'));
        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(operationKey: 'k'));

        $this->assertSame(1, $fired);
    }

    public function test_the_same_key_with_a_different_payload_is_refused_with_a_translated_error_and_changes_nothing(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: 500);
        [$line] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(operationKey: 'k'));

        $different = [
            'another quantity' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(operationKey: 'k')),
            'another restock choice' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2, false), $this->at(), null, new RefundRequest(operationKey: 'k')),
            'an entered amount' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(1500)], operationKey: 'k')),
            'a shipping refund' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(shipping: $this->eur(100), operationKey: 'k')),
            'a deduction' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(deduction: $this->eur(100), deductionReason: 'x', operationKey: 'k')),
            'another channel' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(channel: RefundChannel::BANK, operationKey: 'k')),
            'another reason' => fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), 'a different reason', new RefundRequest(operationKey: 'k')),
            'another action' => fn () => $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(operationKey: 'k')),
        ];

        foreach ($different as $what => $operation) {
            try {
                $operation();
                $this->fail("{$what} under a reused key must be refused.");
            } catch (OperationKeyReusedException $exception) {
                $this->assertStringContainsString('already submitted', $exception->getMessage(), $what);
            }
        }

        $this->assertSame(12, $this->stockOf($order['variationIds'][0]), 'none of them did anything');
        $this->assertCount(1, $this->refundsOf($order['payment']));
        $this->assertSame(['returned', 'refund_owed'], $this->eventTypesOf($order['orderId']));
    }

    public function test_the_error_is_translated_into_bulgarian_too(): void
    {
        app()->setLocale('bg');

        $this->assertStringContainsString('вече е изпратена', (new OperationKeyReusedException())->getMessage());
    }

    public function test_the_key_and_the_hash_sit_on_the_first_event_and_the_refund_event_carries_the_refund(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $result = $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 2), $this->at(), null, new RefundRequest(operationKey: 'stored-key'));

        $events = DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->get();

        $this->assertSame('returned', $events[0]->type);
        $this->assertSame('stored-key', $events[0]->operation_key, 'the FIRST event the operation writes');
        $this->assertSame(64, strlen((string) $events[0]->operation_payload_hash));
        $this->assertSame(RefundOperationFingerprint::of('return', $this->returning($order['saleLineIds'][0], 2), [], new RefundRequest(operationKey: 'stored-key'), null), $events[0]->operation_payload_hash);
        $this->assertNull($events[1]->operation_key);
        $this->assertSame('refund_owed', $events[1]->type);
        $this->assertSame((int) $result->refund()->id(), (int) $events[1]->payment_refund_id);
    }

    public function test_a_cancel_that_writes_no_refund_is_covered_too_and_the_key_is_on_its_first_event(): void
    {
        // No payment at all: nothing to refund, still one operation.
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false);

        $first = $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(operationKey: 'no-money'));
        $second = $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(operationKey: 'no-money'));

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($second->wasReplay());
        $this->assertNull($second->refund());
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]));
        $this->assertSame(['returned', 'status_changed'], $this->eventTypesOf($order['orderId']));
    }

    public function test_a_key_is_per_order(): void
    {
        $a = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);
        $b = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        $this->changer()->recordReturn($a['orderId'], $this->returning($a['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(operationKey: 'shared'));
        $resultB = $this->changer()->recordReturn($b['orderId'], $this->returning($b['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(operationKey: 'shared'));

        $this->assertFalse($resultB->wasReplay(), 'the same key on another order is its own operation');
        $this->assertCount(1, $this->refundsOf($b['payment']));
    }

    public function test_without_a_key_nothing_is_idempotent_exactly_as_before(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at());
        $second = $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at());

        $this->assertFalse($second->wasReplay());
        $this->assertCount(2, $this->refundsOf($order['payment']));
    }

    public function test_the_database_refuses_a_second_row_with_the_same_key_on_an_order(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);
        $row = fn () => [
            'order_id' => $order['orderId'], 'type' => 'note_added', 'reason' => 'x', 'occurred_at' => now(),
            'operation_key' => 'dup', 'operation_payload_hash' => str_repeat('a', 64),
        ];

        DB::table('order_events')->insert($row());

        try {
            DB::table('order_events')->insert($row());
            $this->fail('UNIQUE (order_id, operation_key) must reject the duplicate.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->errorInfo[0]);
            $this->assertSame(1062, (int) $exception->errorInfo[1]);
        }
    }

    public function test_a_key_without_its_hash_is_rejected_by_the_check(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        try {
            DB::table('order_events')->insert([
                'order_id' => $order['orderId'], 'type' => 'note_added', 'reason' => 'x', 'occurred_at' => now(), 'operation_key' => 'lonely',
            ]);
            $this->fail('a key and its hash are both present or both absent.');
        } catch (QueryException $exception) {
            $this->assertContains((int) $exception->errorInfo[1], [3819, 4025]);
        }
    }

    public function test_the_fingerprint_ignores_the_order_a_form_submitted_things_in(): void
    {
        $lines = [
            ['originatingSaleLineId' => '2', 'quantityReturned' => 1, 'restock' => true],
            ['originatingSaleLineId' => '1', 'quantityReturned' => 3, 'restock' => false],
        ];
        $reversed = array_reverse($lines);

        $this->assertSame(
            RefundOperationFingerprint::of('return', $lines, [], null, 'r'),
            RefundOperationFingerprint::of('return', $reversed, [], null, ' r '),
        );
        $this->assertNotSame(
            RefundOperationFingerprint::of('return', $lines, [], null, 'r'),
            RefundOperationFingerprint::of('cancel', $lines, [], null, 'r'),
        );
    }
}
