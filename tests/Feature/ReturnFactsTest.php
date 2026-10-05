<?php

namespace Tests\Feature;

use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\ReturnAnnouncedDateException;
use App\Services\OrderEventRecorder;
use App\Services\OrderReturnFactsReader;
use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use App\Enums\OrderEventType;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R3 part 1 (shipping-domain-design.md §7.2.6, owner decision Q3): the
 * announced-return date on the `returned` history row, and the one reader of the
 * return facts. Facts, not rules: nothing here enforces a deadline.
 *
 * The order is placed 2026-09-28 09:00; the fixture clock (at()) is 2026-09-28 12:00.
 */
class ReturnFactsTest extends TestCase
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

    private function returning(string $lineId, int $quantity = 1): array
    {
        return [['originatingSaleLineId' => $lineId, 'quantityReturned' => $quantity, 'restock' => true]];
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    // ---- the announced-return date ---------------------------------------------------------------

    public function test_the_announced_date_is_stored_on_the_returned_row_and_read_back(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 2), $this->at(), 'wrong size', new RefundRequest(announcedReturnAt: $this->date('2026-09-28 10:30:00')));

        $rows = DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->get();
        $this->assertSame(['returned', 'refund_owed'], $rows->pluck('type')->all());
        $this->assertSame('2026-09-28 10:30:00', $rows[0]->announced_return_at, 'on the returned row');
        $this->assertNull($rows[1]->announced_return_at, 'and on no other');

        $facts = app(OrderReturnFactsReader::class)->forOrder($order['orderId']);
        $this->assertSame('2026-09-28 10:30:00', $facts->returns[0]->announcedAt->format('Y-m-d H:i:s'));
    }

    public function test_a_return_without_an_announced_date_stores_none(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at());

        $this->assertNull(DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'returned')->value('announced_return_at'));
        $this->assertNull(app(OrderReturnFactsReader::class)->forOrder($order['orderId'])->returns[0]->announcedAt);
    }

    public function test_the_announced_date_may_be_the_placement_instant_or_the_recording_instant(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, new RefundRequest(announcedReturnAt: $this->date('2026-09-28 09:00:00')));
        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, new RefundRequest(announcedReturnAt: $this->date('2026-09-28 12:00:00')));

        $this->assertCount(2, app(OrderReturnFactsReader::class)->forOrder($order['orderId'])->returns);
    }

    public function test_an_announced_date_in_the_future_is_refused_and_nothing_is_written(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 2), $this->at(), null, new RefundRequest(announcedReturnAt: $this->date('2026-09-28 12:00:01')));
            $this->fail('the customer cannot have announced a return in the future.');
        } catch (ReturnAnnouncedDateException $exception) {
            $this->assertSame(ReturnAnnouncedDateException::IN_FUTURE, $exception->reason);
            $this->assertStringContainsString('cannot be in the future', $exception->getMessage());
        }

        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'the goods half rolled back too');
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
    }

    public function test_an_announced_date_before_the_order_was_placed_is_refused_and_nothing_is_written(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 2), $this->at(), null, new RefundRequest(announcedReturnAt: $this->date('2026-09-28 08:59:59')));
            $this->fail('the customer cannot have announced a return before the order existed.');
        } catch (ReturnAnnouncedDateException $exception) {
            $this->assertSame(ReturnAnnouncedDateException::BEFORE_PLACEMENT, $exception->reason);
        }

        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
    }

    public function test_the_announced_date_is_part_of_what_a_reused_operation_key_must_repeat(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $request = fn (string $announced) => new RefundRequest(operationKey: 'k', announcedReturnAt: $this->date($announced));

        $first = $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, $request('2026-09-28 10:00:00'));
        $again = $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, $request('2026-09-28 10:00:00'));

        $this->assertTrue($again->wasReplay());
        $this->assertSame($first->refund()->id(), $again->refund()->id());

        $this->expectException(OperationKeyReusedException::class);
        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, $request('2026-09-28 11:00:00'));
    }

    public function test_cancel_takes_no_announced_date(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        try {
            $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(announcedReturnAt: $this->date('2026-09-28 10:00:00')));
            $this->fail('a cancellation has no announced return.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('only recordReturn()', $exception->getMessage());
        }

        $this->assertSame([], $this->eventTypesOf($order['orderId']));
    }

    public function test_the_recorder_and_the_database_keep_the_date_on_returned_rows_only(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        try {
            app(OrderEventRecorder::class)->record($order['orderId'], OrderEventType::NOTE_ADDED, null, null, 'a note', null, $this->at(), announcedReturnAt: $this->date('2026-09-28 10:00:00'));
            $this->fail('the recorder refuses an announced date on any other event.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('returned event only', $exception->getMessage());
        }

        // The CHECK is the backstop behind that rule (CLAUDE.md rule 2): a raw insert cannot get round it.
        $this->expectException(QueryException::class);
        DB::table('order_events')->insert([
            'order_id' => $order['orderId'],
            'type' => 'note_added',
            'reason' => 'x',
            'occurred_at' => '2026-09-28 12:00:00',
            'announced_return_at' => '2026-09-28 10:00:00',
        ]);
    }

    // ---- the return facts reader -----------------------------------------------------------------

    public function test_the_facts_of_a_delivered_order_are_the_delivery_date_the_return_dates_and_the_days_between(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->changer()->deliver($order['orderId'], $this->date('2026-09-29 10:00:00'));
        $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning($order['saleLineIds'][0], 2),
            $this->date('2026-10-05 12:00:00'),
            'too small',
            new RefundRequest(announcedReturnAt: $this->date('2026-10-03 09:00:00')),
        );

        $facts = app(OrderReturnFactsReader::class)->forOrder($order['orderId']);

        $this->assertSame('2026-09-29 10:00:00', $facts->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertCount(1, $facts->returns);
        $return = $facts->returns[0];
        $this->assertSame('2026-10-03 09:00:00', $return->announcedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 12:00:00', $return->recordedAt->format('Y-m-d H:i:s'));
        $this->assertNotNull($return->transactionId);
        $this->assertSame(3, $return->daysFromDeliveryToAnnounced, '3 days 23 hours = 3 whole days');
        $this->assertSame(6, $return->daysFromDeliveryToRecorded);
        $this->assertSame(11, $facts->daysSinceDelivery($this->date('2026-10-10 10:00:00')));
        $this->assertSame(10, $facts->daysSinceDelivery($this->date('2026-10-10 09:59:59')), 'whole 24-hour periods');
    }

    public function test_every_return_of_the_order_is_listed_oldest_first_each_with_its_own_dates(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $this->changer()->deliver($order['orderId'], $this->date('2026-09-29 10:00:00'));

        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->date('2026-10-02 10:00:00'), null, new RefundRequest(announcedReturnAt: $this->date('2026-10-01 10:00:00')));
        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->date('2026-10-09 10:00:00'));

        $returns = app(OrderReturnFactsReader::class)->forOrder($order['orderId'])->returns;

        $this->assertCount(2, $returns);
        $this->assertSame([3, 10], [$returns[0]->daysFromDeliveryToRecorded, $returns[1]->daysFromDeliveryToRecorded]);
        $this->assertSame(2, $returns[0]->daysFromDeliveryToAnnounced);
        $this->assertNull($returns[1]->announcedAt, 'nobody entered it');
        $this->assertNull($returns[1]->daysFromDeliveryToAnnounced);
    }

    public function test_no_deadline_is_enforced_whatever_the_number_of_days(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->changer()->deliver($order['orderId'], $this->date('2026-09-29 10:00:00'));

        $result = $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning($order['saleLineIds'][0], 2),
            $this->date('2027-11-01 10:00:00'),
            null,
            new RefundRequest(announcedReturnAt: $this->date('2027-10-30 10:00:00')),
        );

        $this->assertNotNull($result->refund(), 'a return more than a year after the delivery is still recorded and refunded');
        $facts = app(OrderReturnFactsReader::class)->forOrder($order['orderId']);
        $this->assertSame(396, $facts->returns[0]->daysFromDeliveryToAnnounced, '2026-09-29 to 2027-10-30: 365 + 31');
        $this->assertSame(398, $facts->returns[0]->daysFromDeliveryToRecorded, '2026-09-29 to 2027-11-01: 365 + 33');
    }

    public function test_the_facts_of_an_order_never_delivered_are_empty(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $none = app(OrderReturnFactsReader::class)->forOrder($order['orderId']);
        $this->assertNull($none->deliveredAt);
        $this->assertSame([], $none->returns);
        $this->assertNull($none->daysSinceDelivery($this->date('2026-10-10 10:00:00')));

        // A return recorded while the order was only shipped: the return is a fact, the day counts are not.
        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at(), null, new RefundRequest(announcedReturnAt: $this->date('2026-09-28 10:00:00')));

        $facts = app(OrderReturnFactsReader::class)->forOrder($order['orderId']);
        $this->assertNull($facts->deliveredAt);
        $this->assertNull($facts->daysSinceDelivery($this->date('2026-10-10 10:00:00')));
        $this->assertCount(1, $facts->returns);
        $this->assertNull($facts->returns[0]->daysFromDeliveryToAnnounced);
        $this->assertNull($facts->returns[0]->daysFromDeliveryToRecorded);
        $this->assertNotNull($facts->returns[0]->announcedAt);
    }

    public function test_the_reader_makes_one_query_and_reads_only_this_orders_events(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $other = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->changer()->deliver($other['orderId'], $this->date('2026-09-29 10:00:00'));
        $this->changer()->recordReturn($other['orderId'], $this->returning($other['saleLineIds'][0]), $this->date('2026-10-01 10:00:00'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $facts = app(OrderReturnFactsReader::class)->forOrder($order['orderId']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertNull($facts->deliveredAt);
        $this->assertSame([], $facts->returns);
    }
}
