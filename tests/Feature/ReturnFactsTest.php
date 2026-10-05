<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Models\OrderEventModel;
use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\ReturnAnnouncedDateException;
use App\Services\OrderEventRecorder;
use App\Services\OrderReturnFactsReader;
use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R3 (shipping-domain-design.md §7.2.6, owner decision Q3): the announced-return
 * DAY on the `returned` history row, and the one reader of the return facts. The day is a
 * CALENDAR DAY in the STORE timezone (a plain 'Y-m-d', never an instant), and every day
 * count is in calendar days of that zone. Facts, not rules: nothing here enforces a deadline.
 *
 * The store timezone is Europe/Sofia (UTC+3 until 25 Oct 2026). The order is placed
 * 2026-09-28 09:00 UTC (12:00 in Sofia); the fixture clock (at()) is 2026-09-28 12:00 UTC.
 */
class ReturnFactsTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
    }

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    private function returning(string $lineId, int $quantity = 1): array
    {
        return [['originatingSaleLineId' => $lineId, 'quantityReturned' => $quantity, 'restock' => true]];
    }

    /** A UTC instant. */
    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function placeAt(string $orderId, string $utc): void
    {
        DB::table('orders')->where('id', $orderId)->update(['placed_at' => $utc]);
    }

    private function facts(string $orderId)
    {
        return app(OrderReturnFactsReader::class)->forOrder($orderId);
    }

    // ---- the announced-return day ----------------------------------------------------------------

    public function test_the_picked_day_is_stored_and_read_back_unchanged_in_the_sofia_zone(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        // Recorded at 00:30 on 29 Sep in Sofia (21:30 UTC on the 28th): the day the merchant picked must not move.
        $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning($order['saleLineIds'][0], 2),
            $this->utc('2026-09-28 21:30:00'),
            'wrong size',
            new RefundRequest(announcedReturnOn: '2026-09-29'),
        );

        $rows = DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->get();
        $this->assertSame(['returned', 'refund_owed'], $rows->pluck('type')->all());
        $this->assertSame('2026-09-29', $rows[0]->announced_return_on, 'the raw column holds the entered day');
        $this->assertNull($rows[1]->announced_return_on, 'and no other row holds one');
        $this->assertSame('2026-09-29', OrderEventModel::find($rows[0]->id)->announced_return_on->format('Y-m-d'), 'the model reads it back as the same day');
        $this->assertSame('2026-09-29', $this->facts($order['orderId'])->returns[0]->announcedOn);
    }

    public function test_a_return_without_an_announced_day_stores_none(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at());

        $this->assertNull(DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'returned')->value('announced_return_on'));
        $this->assertNull($this->facts($order['orderId'])->returns[0]->announcedOn);
    }

    public function test_a_return_recorded_after_midnight_in_sofia_accepts_that_day_and_refuses_the_next(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $recordedAt = $this->utc('2026-09-28 21:30:00'); // 2026-09-29 00:30 in Sofia

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $recordedAt, null, new RefundRequest(announcedReturnOn: '2026-09-30'));
            $this->fail('30 Sep is still in the future for the merchant.');
        } catch (ReturnAnnouncedDateException $exception) {
            $this->assertSame(ReturnAnnouncedDateException::IN_FUTURE, $exception->reason);
        }

        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'the goods half rolled back too');
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());

        // 29 Sep is today for the merchant (although it is still the 28th in UTC).
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $recordedAt, null, new RefundRequest(announcedReturnOn: '2026-09-29'));
        $this->assertSame('2026-09-29', $this->facts($order['orderId'])->returns[0]->announcedOn);
    }

    public function test_the_placement_day_in_the_store_zone_is_accepted_and_the_day_before_is_refused(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        // 2026-09-27 22:30 UTC is 01:30 on 28 Sep in Sofia: the order was placed on the 28th for the merchant.
        $this->placeAt($order['orderId'], '2026-09-27 22:30:00');

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, new RefundRequest(announcedReturnOn: '2026-09-27'));
            $this->fail('the day before the order was placed is impossible, although it is the placement day in UTC.');
        } catch (ReturnAnnouncedDateException $exception) {
            $this->assertSame(ReturnAnnouncedDateException::BEFORE_PLACEMENT, $exception->reason);
            $this->assertStringContainsString('before the order was placed', $exception->getMessage());
        }

        $this->assertSame([], $this->eventTypesOf($order['orderId']));

        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, new RefundRequest(announcedReturnOn: '2026-09-28'));
        $this->assertSame('2026-09-28', $this->facts($order['orderId'])->returns[0]->announcedOn);
    }

    public function test_the_recording_day_itself_is_accepted(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at(), null, new RefundRequest(announcedReturnOn: '2026-09-28'));

        $this->assertCount(1, $this->facts($order['orderId'])->returns);
    }

    public function test_the_announced_day_must_be_a_real_date_written_y_m_d(): void
    {
        foreach (['2026-02-30', '2026-9-1', '29/09/2026', '2026-09-29 10:00:00', 'tomorrow', ''] as $bad) {
            try {
                new RefundRequest(announcedReturnOn: $bad);
                $this->fail("\"{$bad}\" is not a calendar day.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('announced return day', $exception->getMessage());
            }
        }

        $this->assertSame('2026-09-29', (new RefundRequest(announcedReturnOn: '2026-09-29'))->announcedReturnOn);
    }

    public function test_the_announced_day_is_part_of_what_a_reused_operation_key_must_repeat(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $request = fn (string $day) => new RefundRequest(operationKey: 'k', announcedReturnOn: $day);

        $first = $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, $request('2026-09-28'));
        $again = $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, $request('2026-09-28'));

        $this->assertTrue($again->wasReplay());
        $this->assertSame($first->refund()->id(), $again->refund()->id());

        $this->expectException(OperationKeyReusedException::class);
        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->at(), null, $request('2026-09-27'));
    }

    public function test_the_hash_of_an_operation_without_an_announced_day_is_what_it_always_was(): void
    {
        $with = \App\Services\RefundOperationFingerprint::of('return', [], [], new RefundRequest(announcedReturnOn: '2026-09-28'), null);
        $without = \App\Services\RefundOperationFingerprint::of('return', [], [], new RefundRequest(), null);
        $none = \App\Services\RefundOperationFingerprint::of('return', [], [], null, null);

        $this->assertNotSame($with, $without);
        $this->assertSame($without, $none, 'no day in the contents, so nothing changes for an operation without one');
    }

    public function test_cancel_takes_no_announced_day(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        try {
            $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(announcedReturnOn: '2026-09-28'));
            $this->fail('a cancellation has no announced return.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('only recordReturn()', $exception->getMessage());
        }

        $this->assertSame([], $this->eventTypesOf($order['orderId']));
    }

    public function test_the_recorder_and_the_database_keep_the_day_on_returned_rows_only(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        try {
            app(OrderEventRecorder::class)->record($order['orderId'], OrderEventType::NOTE_ADDED, null, null, 'a note', null, $this->at(), announcedReturnOn: '2026-09-28');
            $this->fail('the recorder refuses an announced day on any other event.');
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
            'announced_return_on' => '2026-09-28',
        ]);
    }

    // ---- the return facts reader -----------------------------------------------------------------

    public function test_a_delivery_after_midnight_in_sofia_and_a_return_just_before_midnight_14_days_later_is_14_days(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        // 22:30 UTC on 29 Sep is 01:30 on 30 Sep in Sofia; 20:59 UTC on 14 Oct is 23:59 on 14 Oct in Sofia.
        $this->changer()->deliver($order['orderId'], $this->utc('2026-09-29 22:30:00'));
        $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning($order['saleLineIds'][0], 2),
            $this->utc('2026-10-14 20:59:00'),
            'too small',
            new RefundRequest(announcedReturnOn: '2026-10-13'),
        );

        $facts = $this->facts($order['orderId']);

        $this->assertSame('2026-09-29 22:30:00', $facts->deliveredAt->format('Y-m-d H:i:s'), 'the delivery stays an instant');
        $this->assertSame('2026-09-30', $facts->deliveredOn());
        $return = $facts->returns[0];
        $this->assertSame('2026-10-14 20:59:00', $return->recordedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13', $return->announcedOn);
        $this->assertNotNull($return->transactionId);
        $this->assertSame(14, $return->daysFromDeliveryToRecorded, '14 calendar days in Sofia (it would be 13 in 24-hour periods)');
        $this->assertSame(13, $return->daysFromDeliveryToAnnounced);
        $this->assertSame(11, $facts->daysSinceDelivery($this->utc('2026-10-10 21:30:00')), '11 Oct 00:30 in Sofia (10 in 24-hour periods)');
        $this->assertSame(10, $facts->daysSinceDelivery($this->utc('2026-10-10 20:30:00')), '10 Oct 23:30 in Sofia');
        $this->assertSame(0, $facts->daysSinceDelivery($this->utc('2026-09-29 22:31:00')), 'the delivery day itself is day 0');
    }

    public function test_a_count_is_negative_when_the_day_precedes_the_delivery_day_and_is_never_clamped(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        // The delivery was recorded late: on 5 Oct, although the customer announced a return for 3 Oct.
        $this->changer()->deliver($order['orderId'], $this->utc('2026-10-05 10:00:00'));
        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->utc('2026-10-06 10:00:00'), null, new RefundRequest(announcedReturnOn: '2026-10-03'));

        $facts = $this->facts($order['orderId']);

        $this->assertSame(-2, $facts->returns[0]->daysFromDeliveryToAnnounced);
        $this->assertSame(1, $facts->returns[0]->daysFromDeliveryToRecorded);
        $this->assertSame(-1, $facts->daysSinceDelivery($this->utc('2026-10-04 10:00:00')), 'as-of before the delivery day');
    }

    public function test_every_return_of_the_order_is_listed_oldest_first_each_with_its_own_days(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $this->changer()->deliver($order['orderId'], $this->utc('2026-09-29 10:00:00'));

        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->utc('2026-10-02 10:00:00'), null, new RefundRequest(announcedReturnOn: '2026-10-01'));
        $this->changer()->recordReturn($order['orderId'], $this->returning($line), $this->utc('2026-10-09 10:00:00'));

        $returns = $this->facts($order['orderId'])->returns;

        $this->assertCount(2, $returns);
        $this->assertSame([3, 10], [$returns[0]->daysFromDeliveryToRecorded, $returns[1]->daysFromDeliveryToRecorded]);
        $this->assertSame(2, $returns[0]->daysFromDeliveryToAnnounced);
        $this->assertNull($returns[1]->announcedOn, 'nobody entered it');
        $this->assertNull($returns[1]->daysFromDeliveryToAnnounced);
    }

    public function test_no_deadline_is_enforced_whatever_the_number_of_days(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->changer()->deliver($order['orderId'], $this->utc('2026-09-29 10:00:00'));

        $result = $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning($order['saleLineIds'][0], 2),
            $this->utc('2027-11-01 10:00:00'),
            null,
            new RefundRequest(announcedReturnOn: '2027-10-30'),
        );

        $this->assertNotNull($result->refund(), 'a return more than a year after the delivery is still recorded and refunded');
        $facts = $this->facts($order['orderId']);
        $this->assertSame(396, $facts->returns[0]->daysFromDeliveryToAnnounced, '2026-09-29 to 2027-10-30: 365 + 31');
        $this->assertSame(398, $facts->returns[0]->daysFromDeliveryToRecorded, '2026-09-29 to 2027-11-01: 365 + 33');
    }

    public function test_the_facts_of_an_order_never_delivered_are_empty(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $none = $this->facts($order['orderId']);
        $this->assertNull($none->deliveredAt);
        $this->assertNull($none->deliveredOn());
        $this->assertSame([], $none->returns);
        $this->assertNull($none->daysSinceDelivery($this->utc('2026-10-10 10:00:00')));

        // A return recorded while the order was only shipped: the return is a fact, the day counts are not.
        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at(), null, new RefundRequest(announcedReturnOn: '2026-09-28'));

        $facts = $this->facts($order['orderId']);
        $this->assertNull($facts->deliveredAt);
        $this->assertCount(1, $facts->returns);
        $this->assertNull($facts->returns[0]->daysFromDeliveryToAnnounced);
        $this->assertNull($facts->returns[0]->daysFromDeliveryToRecorded);
        $this->assertSame('2026-09-28', $facts->returns[0]->announcedOn);
    }

    public function test_the_reader_makes_one_query_and_reads_only_this_orders_events(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $other = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        $this->changer()->deliver($other['orderId'], $this->utc('2026-09-29 10:00:00'));
        $this->changer()->recordReturn($other['orderId'], $this->returning($other['saleLineIds'][0]), $this->utc('2026-10-01 10:00:00'));
        app(SiteSettingsRepository::class)->get('site.timezone'); // warm: the settings read is not the reader's own

        DB::flushQueryLog();
        DB::enableQueryLog();
        $facts = $this->facts($order['orderId']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertNull($facts->deliveredAt);
        $this->assertSame([], $facts->returns);
    }
}
