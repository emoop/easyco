<?php

namespace Tests\Feature;

use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\PaymentReceiptRefusedException;
use App\Services\MoneyInput;
use App\Services\PaymentReceiptReader;
use App\Services\PaymentReceiptRecorder;
use App\Services\PaymentReceiptState;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * Refunds R4a-2 (shipping-domain-design.md §7.2.20 §3, §5, §10): the bank-transfer receipt service.
 * Tests are named after the rules. 100.00 EUR is expected; the order was placed 2026-09-28 09:00 UTC
 * and the clock is 2026-09-28 12:00 UTC.
 */
class PaymentReceiptRecorderTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    /** @var list<string> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();

        foreach (['order.payment_receipt_recorded', 'order.payment_confirmed', 'order.payment_receipt_corrected', 'order.payment_mismatch_accepted'] as $name) {
            Hook::action($name, function () use ($name): void {
                $this->hooks[] = $name;
            });
        }
    }

    private function recorder(): PaymentReceiptRecorder
    {
        return app(PaymentReceiptRecorder::class);
    }

    private function record(array $order, int $minor, string $day = '2026-09-28', string $reference = 'BG-REF-1', ?string $key = null, ?DateTimeImmutable $at = null): \App\Services\PaymentReceiptResult
    {
        return $this->recorder()->record((string) $order['payment']->id(), $this->eur($minor), $day, $reference, $at ?? $this->at(), $key);
    }

    private function refusal(callable $call): PaymentReceiptRefusedException
    {
        try {
            $call();
        } catch (PaymentReceiptRefusedException $exception) {
            return $exception;
        }

        $this->fail('expected a PaymentReceiptRefusedException.');
    }

    private function assertRefusedAndNothingWritten(string $reason, array $order, callable $call, ?string $field = null): void
    {
        $before = [DB::table('payment_receipts')->count(), DB::table('order_events')->count(), $this->paymentRow($order['payment'])->confirmed_at];
        $this->hooks = [];

        $exception = $this->refusal($call);

        $this->assertSame($reason, $exception->reason);
        $this->assertSame($field, $exception->field(), "field of {$reason}");
        $this->assertNotSame('', $exception->getMessage());
        $this->assertStringNotContainsString('orders.payment_receipt', $exception->getMessage(), 'a translated sentence, not a key');
        $this->assertSame($before, [DB::table('payment_receipts')->count(), DB::table('order_events')->count(), $this->paymentRow($order['payment'])->confirmed_at], 'nothing written');
        $this->assertSame([], $this->hooks, 'no hook on a refusal');
    }

    private function eventsOf(string $orderId): array
    {
        return DB::table('order_events')->where('order_id', $orderId)->orderBy('id')->get()->all();
    }

    // --- the exact match -------------------------------------------------------------------------------------

    public function test_an_exact_transfer_settles_the_payment_and_records_day_amount_reference_and_staff(): void
    {
        $staff = $this->actingAsAdministrator();
        $order = $this->bankOrder();

        $result = $this->record($order, 10000, '2026-09-28', '  BG-REF-1  ', 'k-exact');

        $this->assertFalse($result->wasReplay());
        $this->assertTrue($result->settled());
        $this->assertTrue($result->payment()->isSettled());

        $row = DB::table('payment_receipts')->first();
        $this->assertSame((string) $order['payment']->id(), (string) $row->payment_id);
        $this->assertSame(10000, (int) $row->amount_minor);
        $this->assertSame('EUR', $row->amount_currency);
        $this->assertSame('2026-09-28', substr((string) $row->received_on, 0, 10), 'the day, unshifted');
        $this->assertSame('BG-REF-1', $row->bank_reference, 'trimmed');
        $this->assertSame((string) $staff->id, $row->recorded_by);
        $this->assertSame('2026-09-28 12:00:00', $row->recorded_at);
        $this->assertNull($row->supersedes_receipt_id);

        $payment = $this->paymentRow($order['payment']);
        $this->assertSame('2026-09-28 12:00:00', $payment->confirmed_at, 'confirmed_at is the instant of recording');
        $this->assertNull($payment->settled_amount_minor, 'an exact settlement leaves the settled amount NULL');
        $this->assertNull($payment->settlement_reason);

        $events = $this->eventsOf($order['orderId']);
        $this->assertSame(['payment_receipt_recorded', 'payment_confirmed'], array_map(fn ($e) => $e->type, $events));
        $this->assertSame('BG-REF-1', $events[0]->reason, 'reason = the bank reference');
        $this->assertSame('k-exact', $events[0]->operation_key);
        $this->assertSame((string) $order['payment']->id(), (string) $events[0]->payment_id);
        $this->assertSame((string) $row->id, (string) $events[0]->payment_receipt_id);
        $this->assertSame((string) $staff->id, (string) $events[0]->staff_id);
        $this->assertNull($events[0]->from_status);
        $this->assertNull($events[1]->operation_key, 'the key sits on the receipt event only');

        $this->assertSame(['order.payment_receipt_recorded', 'order.payment_confirmed'], $this->hooks);
    }

    public function test_two_parts_that_add_up_settle_on_the_second(): void
    {
        $order = $this->bankOrder();

        $first = $this->record($order, 6000, reference: 'PART-1');
        $this->assertFalse($first->settled());
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(['order.payment_receipt_recorded'], $this->hooks);

        $second = $this->record($order, 4000, reference: 'PART-2');
        $this->assertTrue($second->settled());
        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertNull($this->paymentRow($order['payment'])->settled_amount_minor);

        $this->assertSame(2, DB::table('payment_receipts')->count());
        $this->assertSame(
            ['payment_receipt_recorded', 'payment_receipt_recorded', 'payment_confirmed'],
            array_map(fn ($e) => $e->type, $this->eventsOf($order['orderId'])),
        );
        $this->assertSame(['order.payment_receipt_recorded', 'order.payment_receipt_recorded', 'order.payment_confirmed'], $this->hooks);
    }

    // --- the mismatch ----------------------------------------------------------------------------------------

    public function test_a_short_transfer_is_recorded_and_does_not_settle_the_payment(): void
    {
        $order = $this->bankOrder();

        $result = $this->record($order, 9000);

        $this->assertFalse($result->settled());
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertNull($this->paymentRow($order['payment'])->confirmed_at, 'confirmed_at stays NULL');
        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(['payment_receipt_recorded'], array_map(fn ($e) => $e->type, $this->eventsOf($order['orderId'])), 'no payment_confirmed');
        $this->assertSame(['order.payment_receipt_recorded'], $this->hooks, 'the recorded hook only');

        $status = app(PaymentReceiptReader::class)->forPayment($this->freshPayment($order['payment']));
        $this->assertSame(PaymentReceiptState::PARTIAL, $status->state());
        $this->assertSame(-1000, $status->difference()->minorValue());
    }

    public function test_an_over_transfer_is_recorded_and_does_not_settle_the_payment(): void
    {
        $order = $this->bankOrder();

        $result = $this->record($order, 11000);

        $this->assertFalse($result->settled());
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(['payment_receipt_recorded'], array_map(fn ($e) => $e->type, $this->eventsOf($order['orderId'])));
        $this->assertSame(['order.payment_receipt_recorded'], $this->hooks);

        $status = app(PaymentReceiptReader::class)->forPayment($this->freshPayment($order['payment']));
        $this->assertSame(PaymentReceiptState::MISMATCH, $status->state());
        $this->assertSame(1000, $status->difference()->minorValue());
    }

    public function test_a_second_part_that_overshoots_leaves_the_payment_unsettled(): void
    {
        $order = $this->bankOrder();

        $this->record($order, 6000, reference: 'A');
        $second = $this->record($order, 5000, reference: 'B');

        $this->assertFalse($second->settled());
        $status = app(PaymentReceiptReader::class)->forPayment($this->freshPayment($order['payment']));
        $this->assertSame(11000, $status->received()->minorValue());
        $this->assertSame(2, $status->effectiveCount());
        $this->assertSame(PaymentReceiptState::MISMATCH, $status->state());
    }

    // --- the reader ------------------------------------------------------------------------------------------

    public function test_the_receipt_reader_reports_none_partial_mismatch_and_settled(): void
    {
        $reader = app(PaymentReceiptReader::class);
        $order = $this->bankOrder();

        $none = $reader->forPayment($order['payment']);
        $this->assertSame(PaymentReceiptState::NONE, $none->state());
        $this->assertSame(10000, $none->expected()->minorValue());
        $this->assertSame(0, $none->received()->minorValue());
        $this->assertSame(0, $none->effectiveCount());
        $this->assertSame(-10000, $none->difference()->minorValue());
        $this->assertFalse($none->isUnreconciled());

        $this->record($order, 4000);
        $partial = $reader->forPayment($this->freshPayment($order['payment']));
        $this->assertSame(PaymentReceiptState::PARTIAL, $partial->state());
        $this->assertTrue($partial->isUnreconciled());

        // The live hint: what one more receipt of 6000 would make of it — no arithmetic outside the reader.
        $hint = $partial->withReceipt($this->eur(6000));
        $this->assertTrue($hint->matches());
        $this->assertSame(0, $hint->difference()->minorValue());
        $this->assertFalse($partial->withReceipt($this->eur(7000))->matches());

        $this->record($order, 6000);
        $settled = $reader->forPayment($this->freshPayment($order['payment']));
        $this->assertSame(PaymentReceiptState::SETTLED, $settled->state());
        $this->assertFalse($settled->isUnreconciled());
        $this->assertSame(2, $settled->effectiveCount());
    }

    public function test_the_receipt_reader_ignores_superseded_receipts(): void
    {
        $order = $this->bankOrder();
        $typo = $this->appendReceipt($order['payment'], 6000, 'TYPO');
        $this->appendReceipt($order['payment'], 9000, 'FIXED', supersedes: $typo);

        $status = app(PaymentReceiptReader::class)->forPayment($order['payment']);

        $this->assertSame(9000, $status->received()->minorValue());
        $this->assertSame(1, $status->effectiveCount());
    }

    // --- the limits ------------------------------------------------------------------------------------------

    public function test_the_21st_effective_receipt_is_refused_and_the_20th_is_accepted(): void
    {
        $order = $this->bankOrder();

        for ($i = 1; $i <= 19; $i++) {
            $this->appendReceipt($order['payment'], 100, 'R-'.$i);
        }

        $this->record($order, 100, reference: 'R-20');
        $this->assertSame(20, count(app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id())));

        $this->assertRefusedAndNothingWritten(
            PaymentReceiptRefusedException::TOO_MANY_EFFECTIVE_RECEIPTS,
            $order,
            fn () => $this->record($order, 100, reference: 'R-21'),
        );
    }

    public function test_the_51st_row_is_refused_even_when_most_rows_are_superseded(): void
    {
        $order = $this->bankOrder();

        // A correction chain: 50 rows, ONE of them effective.
        $previous = null;
        for ($i = 1; $i <= 50; $i++) {
            $previous = $this->appendReceipt($order['payment'], 100, 'CHAIN-'.$i, supersedes: $previous);
        }

        $this->assertSame(50, app(PaymentReceiptRepository::class)->countRows((string) $order['payment']->id()));
        $this->assertSame(1, count(app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id())));

        $this->assertRefusedAndNothingWritten(
            PaymentReceiptRefusedException::TOO_MANY_RECEIPT_ROWS,
            $order,
            fn () => $this->record($order, 100, reference: 'ONE-TOO-MANY'),
        );
    }

    // --- the received day ------------------------------------------------------------------------------------

    public function test_a_received_day_in_the_future_is_refused_and_today_is_accepted(): void
    {
        $order = $this->bankOrder();

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::DAY_IN_FUTURE, $order, fn () => $this->record($order, 5000, '2026-09-29'), 'received_on');

        $this->record($order, 5000, '2026-09-28');
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_a_received_day_before_the_placement_day_is_refused_and_the_placement_day_is_accepted(): void
    {
        $order = $this->bankOrder();

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::DAY_BEFORE_PLACEMENT, $order, fn () => $this->record($order, 5000, '2026-09-27'), 'received_on');

        $this->record($order, 5000, '2026-09-28');
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_a_malformed_received_day_is_refused(): void
    {
        $order = $this->bankOrder();

        foreach (['', '2026-9-28', '28.09.2026', '2026-02-30', '2026-13-01', '2026-09-28 10:00', '2026-09-28T10:00:00Z', ' 2026-09-28', "2026-09-28\n", 'today', '２０２６-09-28', '2026-09-28; DROP TABLE payments', str_repeat('9', 5000)] as $bad) {
            $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::DAY_MALFORMED, $order, fn () => $this->record($order, 5000, $bad), 'received_on');
        }
    }

    public function test_the_days_are_store_timezone_days_in_europe_sofia(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');

        // Placed 2026-09-27 21:30 UTC = 2026-09-28 00:30 in Sofia: the placement DAY is the 28th, not the 27th.
        $order = $this->bankOrder();
        DB::table('orders')->where('id', $order['orderId'])->update(['placed_at' => '2026-09-27 21:30:00']);

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::DAY_BEFORE_PLACEMENT, $order, fn () => $this->record($order, 5000, '2026-09-27'), 'received_on');

        // Recorded 2026-09-28 21:30 UTC = 2026-09-29 00:30 in Sofia: TODAY is the 29th, so the 29th is allowed...
        $midnight = new DateTimeImmutable('2026-09-28 21:30:00');
        $this->record($order, 5000, '2026-09-29', 'LATE', at: $midnight);
        $this->assertSame('2026-09-29', substr((string) DB::table('payment_receipts')->value('received_on'), 0, 10), 'never shifted');

        // ...and the 30th is still the future.
        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::DAY_IN_FUTURE, $order, fn () => $this->record($order, 1000, '2026-09-30', 'FUTURE', at: $midnight), 'received_on');

        // The placement day itself (the 28th, store-local) is accepted.
        $this->record($order, 1000, '2026-09-28', 'PLACEMENT-DAY', at: $midnight);
        $this->assertSame(2, DB::table('payment_receipts')->count());
    }

    public function test_just_before_local_midnight_the_utc_date_does_not_leak_into_the_store_day(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
        $order = $this->bankOrder();

        // 2026-09-28 20:59 UTC = 23:59 in Sofia: still the 28th, so the 29th is the future (the minute after, at
        // 21:00 UTC, the store day becomes the 29th while the UTC date is still the 28th — covered above).
        $this->assertRefusedAndNothingWritten(
            PaymentReceiptRefusedException::DAY_IN_FUTURE,
            $order,
            fn () => $this->record($order, 5000, '2026-09-29', at: new DateTimeImmutable('2026-09-28 20:59:00')),
            'received_on',
        );
    }

    // --- the amount ------------------------------------------------------------------------------------------

    public function test_an_amount_of_ten_integer_digits_is_refused_and_nine_are_accepted(): void
    {
        $order = $this->bankOrder();

        $this->assertRefusedAndNothingWritten(
            PaymentReceiptRefusedException::AMOUNT_TOO_LARGE,
            $order,
            fn () => $this->recorder()->record((string) $order['payment']->id(), Money::fromDecimal('1000000000.00', 'EUR'), '2026-09-28', 'BIG', $this->at()),
            'amount',
        );

        $this->recorder()->record((string) $order['payment']->id(), Money::fromDecimal('999999999.99', 'EUR'), '2026-09-28', 'BIG-OK', $this->at());
        $this->assertSame(999999999_99, (int) DB::table('payment_receipts')->value('amount_minor'));
    }

    public function test_a_30_digit_and_a_10_digit_amount_text_never_become_money(): void
    {
        // The service takes Money; the text goes through MoneyInput first (the dialog of R4a-4), whose limits are the same.
        $this->assertNull(MoneyInput::parse(str_repeat('9', 30), 'EUR'));
        $this->assertNull(MoneyInput::parse('1000000000.00', 'EUR'));
        $this->assertNull(MoneyInput::parse(str_repeat('1', 40).'.00', 'EUR'));
        $this->assertNotNull(MoneyInput::parse('999999999.99', 'EUR'));
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $order = $this->bankOrder();

        foreach ([0, -100] as $minor) {
            $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::AMOUNT_NOT_POSITIVE, $order, fn () => $this->record($order, $minor), 'amount');
        }
    }

    public function test_an_amount_in_another_currency_is_refused(): void
    {
        $order = $this->bankOrder();

        $this->assertRefusedAndNothingWritten(
            PaymentReceiptRefusedException::CURRENCY_MISMATCH,
            $order,
            fn () => $this->recorder()->record((string) $order['payment']->id(), Money::fromMinorUnits(10000, 'USD'), '2026-09-28', 'USD-REF', $this->at()),
            'amount',
        );
    }

    // --- the reference ---------------------------------------------------------------------------------------

    public function test_a_blank_reference_is_refused_including_non_ascii_whitespace(): void
    {
        $order = $this->bankOrder();

        foreach (['', '   ', "\t\n", "\u{00A0}", "\u{00A0}\u{00A0} \u{00A0}", "\u{2003}", "\u{200B}", "\u{FEFF}", "\u{3000}"] as $blank) {
            $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::REFERENCE_BLANK, $order, fn () => $this->record($order, 5000, reference: $blank), 'bank_reference');
        }
    }

    public function test_a_reference_of_65_characters_is_refused_and_64_are_accepted(): void
    {
        $order = $this->bankOrder();

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::REFERENCE_TOO_LONG, $order, fn () => $this->record($order, 5000, reference: str_repeat('A', 65)), 'bank_reference');
        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::REFERENCE_TOO_LONG, $order, fn () => $this->record($order, 5000, reference: str_repeat('A', 100000)), 'bank_reference');

        $this->record($order, 5000, reference: str_repeat('A', 64));
        $this->record($order, 1000, reference: str_repeat('Я', 64));
        $this->assertSame(2, DB::table('payment_receipts')->count());
        $this->assertSame(64, mb_strlen((string) DB::table('payment_receipts')->orderByDesc('id')->value('bank_reference')));
    }

    public function test_a_reference_is_trimmed_of_non_ascii_whitespace_at_its_ends(): void
    {
        $order = $this->bankOrder();

        $this->record($order, 5000, reference: "\u{00A0} BG-REF \u{200B}\t");

        $this->assertSame('BG-REF', DB::table('payment_receipts')->value('bank_reference'));
    }

    public function test_a_reference_with_control_characters_or_bad_encoding_is_refused(): void
    {
        $order = $this->bankOrder();

        foreach (["A\nB", "A\0B", "A\tB", "A\u{200B}B", "A\u{202E}B"] as $bad) {
            $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::REFERENCE_INVALID, $order, fn () => $this->record($order, 5000, reference: $bad), 'bank_reference');
        }

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::REFERENCE_INVALID, $order, fn () => $this->record($order, 5000, reference: "A\xC3\x28B"), 'bank_reference');
    }

    public function test_a_hostile_reference_is_stored_as_inert_text(): void
    {
        $order = $this->bankOrder();
        $text = "x'); DROP TABLE payment_receipts;-- <script>alert(1)</script>";

        $this->record($order, 5000, reference: mb_substr($text, 0, 64));

        $this->assertSame(mb_substr($text, 0, 64), DB::table('payment_receipts')->value('bank_reference'));
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    // --- which payments take a receipt -----------------------------------------------------------------------

    public function test_a_non_bank_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder(method: 'cash_on_delivery');

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::NOT_BANK_TRANSFER, $order, fn () => $this->record($order, 10000));
    }

    public function test_a_settled_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $order['payment']->confirm(new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::PAYMENT_SETTLED, $order, fn () => $this->record($order, 10000));
    }

    public function test_a_voided_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $order['payment']->void(new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::PAYMENT_VOIDED, $order, fn () => $this->record($order, 10000));
    }

    public function test_an_unanswered_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder(answered: false);

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::PAYMENT_UNANSWERED, $order, fn () => $this->record($order, 10000));
    }

    public function test_a_failed_payment_is_refused_by_name(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'bank_transfer', settle: false);
        $payment = Payment::create($order['orderId'], 'bank_transfer', $this->eur(10000), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);
        $order['payment'] = $payment;

        $this->assertRefusedAndNothingWritten(PaymentReceiptRefusedException::PAYMENT_NOT_CONFIRMABLE, $order, fn () => $this->record($order, 10000));
    }

    public function test_an_unknown_payment_is_an_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recorder()->record('999999', $this->eur(100), '2026-09-28', 'REF', $this->at());
    }

    // --- idempotency -----------------------------------------------------------------------------------------

    public function test_the_same_key_twice_is_one_receipt_one_event_and_one_hook(): void
    {
        $order = $this->bankOrder();

        $first = $this->record($order, 9000, key: 'k-1');
        $again = $this->record($order, 9000, key: 'k-1');

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($again->wasReplay());
        $this->assertSame($first->receipt()->id(), $again->receipt()->id(), 'the first result');
        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->count());
        $this->assertSame(['order.payment_receipt_recorded'], $this->hooks, 'the replay fires nothing');
    }

    public function test_a_replay_of_a_settling_receipt_returns_the_first_result_even_though_the_payment_is_now_settled(): void
    {
        $order = $this->bankOrder();

        $first = $this->record($order, 10000, key: 'k-settle');
        $this->hooks = [];
        $again = $this->record($order, 10000, key: 'k-settle');

        $this->assertTrue($first->settled());
        $this->assertTrue($again->wasReplay());
        $this->assertTrue($again->settled());
        $this->assertSame($first->receipt()->id(), $again->receipt()->id());
        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(2, DB::table('order_events')->where('order_id', $order['orderId'])->count());
        $this->assertSame([], $this->hooks);
    }

    public function test_the_same_key_with_other_contents_is_refused_and_writes_nothing(): void
    {
        $order = $this->bankOrder();
        $this->record($order, 9000, reference: 'ONE', key: 'k-2');
        $this->hooks = [];

        foreach ([[8000, 'ONE', '2026-09-28'], [9000, 'TWO', '2026-09-28']] as [$minor, $reference, $day]) {
            try {
                $this->record($order, $minor, $day, $reference, 'k-2');
                $this->fail('the same key with other contents must be refused.');
            } catch (OperationKeyReusedException) {
                // expected
            }
        }

        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->count());
        $this->assertSame([], $this->hooks);
    }

    public function test_the_unique_operation_key_index_backs_the_replay_up(): void
    {
        $order = $this->bankOrder();
        $this->record($order, 9000, key: 'k-3');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('order_events')->insert([
            'order_id' => $order['orderId'],
            'type' => 'payment_receipt_recorded',
            'occurred_at' => '2026-09-28 12:00:00',
            'operation_key' => 'k-3',
            'operation_payload_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_an_operation_key_of_65_characters_or_blank_is_an_invalid_argument(): void
    {
        $order = $this->bankOrder();

        foreach ([str_repeat('k', 65), '', '   '] as $key) {
            try {
                $this->record($order, 9000, key: $key);
                $this->fail('a bad operation key must be refused.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, DB::table('payment_receipts')->count());
    }

    public function test_a_call_without_a_key_is_never_a_replay(): void
    {
        $order = $this->bankOrder();

        $this->record($order, 3000);
        $this->record($order, 3000);

        $this->assertSame(2, DB::table('payment_receipts')->count());
    }

    // --- atomicity -------------------------------------------------------------------------------------------

    public function test_a_failure_while_settling_rolls_the_receipt_and_its_event_back(): void
    {
        $order = $this->bankOrder();
        // Another payment of the same order already holds the money: the confirmer's courtesy check refuses the settlement.
        $other = Payment::create($order['orderId'], 'bank_transfer', $this->eur(10000), PaymentStatus::PENDING);
        $other->recordAttemptResult(PaymentStatus::CAPTURED, 'ref', null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($other);

        try {
            $this->record($order, 10000);
            $this->fail('a second settled payment on the order must refuse the settlement.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DB::table('payment_receipts')->count(), 'the receipt rolled back with the failed settlement');
        $this->assertSame(0, DB::table('order_events')->where('order_id', $order['orderId'])->count());
        $this->assertSame([], $this->hooks);
    }
}
