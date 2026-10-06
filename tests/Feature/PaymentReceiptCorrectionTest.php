<?php

namespace Tests\Feature;

use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\PaymentReceiptRefusedException;
use App\Services\PaymentReceiptReader;
use App\Services\PaymentReceiptRecorder;
use App\Services\PaymentReceiptResult;
use App\Services\RefundCapGuard;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * Refunds R4a-3 (shipping-domain-design.md §7.2.20 §4 "Corrections", §10): a correction appends a new receipt
 * that supersedes the old one. BEFORE settlement any field may change and the result is re-evaluated; AFTER
 * settlement (exact or accepted) only the day and the reference may change and the payment is not touched.
 * 100.00 EUR is expected; the order was placed 2026-09-28 09:00 UTC; the clock is 2026-09-28 12:00 UTC.
 */
class PaymentReceiptCorrectionTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    /** @var list<array{string, array}> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();

        foreach (['order.payment_receipt_recorded', 'order.payment_confirmed', 'order.payment_receipt_corrected', 'order.payment_mismatch_accepted'] as $name) {
            Hook::action($name, function (...$arguments) use ($name): void {
                $this->hooks[] = [$name, $arguments];
            });
        }
    }

    private function recorder(): PaymentReceiptRecorder
    {
        return app(PaymentReceiptRecorder::class);
    }

    private function hookNames(): array
    {
        return array_map(fn (array $hook): string => $hook[0], $this->hooks);
    }

    private function receive(array $order, int $minor, string $reference = 'BG-REF-1', string $day = '2026-09-28'): PaymentReceipt
    {
        return $this->recorder()->record((string) $order['payment']->id(), $this->eur($minor), $day, $reference, $this->at())->receipt();
    }

    private function correct(PaymentReceipt $receipt, int $minor, string $day = '2026-09-28', string $reference = 'BG-REF-1', string $reason = 'typo', ?string $key = null, ?DateTimeImmutable $at = null): PaymentReceiptResult
    {
        return $this->recorder()->correct((string) $receipt->id(), $this->eur($minor), $day, $reference, $reason, $at ?? $this->at(), $key);
    }

    private function snapshot(array $order): array
    {
        return [
            DB::table('payment_receipts')->count(),
            DB::table('order_events')->where('order_id', $order['orderId'])->count(),
            (array) $this->paymentRow($order['payment']),
        ];
    }

    private function assertRefused(string $reason, array $order, callable $call, ?string $field = null): void
    {
        $before = $this->snapshot($order);
        $this->hooks = [];

        try {
            $call();
            $this->fail("expected {$reason}.");
        } catch (PaymentReceiptRefusedException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($field, $exception->field(), "field of {$reason}");
            $this->assertStringNotContainsString('orders.payment_receipt', $exception->getMessage(), 'a translated sentence, not a key');
        }

        $this->assertSame($before, $this->snapshot($order), 'nothing written');
        $this->assertSame([], $this->hooks, 'no hook on a refusal');
    }

    private function effectiveSum(array $order): int
    {
        return app(PaymentReceiptRepository::class)->effectiveSum((string) $order['payment']->id(), 'EUR')->minorValue();
    }

    private function eventTypes(array $order): array
    {
        return DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->pluck('type')->all();
    }

    // --- before settlement -----------------------------------------------------------------------------------

    public function test_before_settlement_a_typo_in_the_amount_that_makes_the_sum_equal_the_expected_amount_settles_the_payment(): void
    {
        $staff = $this->actingAsAdministrator();
        $order = $this->bankOrder();
        $typo = $this->receive($order, 9000, 'REF-TYPO');
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->hooks = [];

        $result = $this->correct($typo, 10000, reference: 'REF-FIXED', reason: 'typed 90 instead of 100', key: 'k-fix');

        $this->assertTrue($result->settled());
        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());

        $row = $this->paymentRow($order['payment']);
        $this->assertSame('2026-09-28 12:00:00', $row->confirmed_at);
        $this->assertNull($row->settled_amount_minor, 'an exact settlement leaves the settled amount NULL');
        $this->assertNull($row->settlement_reason);

        $this->assertSame(['payment_receipt_recorded', 'payment_receipt_corrected', 'payment_confirmed'], $this->eventTypes($order));
        $event = DB::table('order_events')->where('type', 'payment_receipt_corrected')->first();
        $this->assertSame('typed 90 instead of 100', $event->reason);
        $this->assertSame((string) $order['payment']->id(), (string) $event->payment_id);
        $this->assertSame((string) $result->receipt()->id(), (string) $event->payment_receipt_id, 'the NEW receipt');
        $this->assertSame('k-fix', $event->operation_key);
        $this->assertSame((string) $staff->id, (string) $event->staff_id);

        $this->assertSame(['order.payment_receipt_corrected', 'order.payment_confirmed'], $this->hookNames());

        $this->assertSame((string) $typo->id(), $result->receipt()->supersedesReceiptId());
        $this->assertSame(10000, $this->effectiveSum($order));
        $this->assertSame(2, DB::table('payment_receipts')->count(), 'the typo row stays, superseded');
    }

    public function test_before_settlement_a_correction_that_leaves_a_mismatch_only_corrects(): void
    {
        $order = $this->bankOrder();
        $first = $this->receive($order, 9000);
        $this->hooks = [];

        $result = $this->correct($first, 8000, reason: 'it was 80');

        $this->assertFalse($result->settled());
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertNull($this->paymentRow($order['payment'])->confirmed_at);
        $this->assertSame(['payment_receipt_recorded', 'payment_receipt_corrected'], $this->eventTypes($order));
        $this->assertSame(['order.payment_receipt_corrected'], $this->hookNames());
        $this->assertSame(8000, $this->effectiveSum($order));
    }

    public function test_before_settlement_the_day_and_the_reference_may_change_too(): void
    {
        $order = $this->bankOrder();
        $first = $this->receive($order, 9000, 'OLD');

        $result = $this->correct($first, 9000, '2026-09-28', 'NEW', 'wrong reference');

        $this->assertSame('NEW', $result->receipt()->bankReference());
        $this->assertSame(9000, $this->effectiveSum($order));
    }

    public function test_a_correction_that_overshoots_the_expected_amount_does_not_settle(): void
    {
        $order = $this->bankOrder();
        $first = $this->receive($order, 9000);

        $result = $this->correct($first, 12000, reason: 'it was 120');

        $this->assertFalse($result->settled());
        $this->assertSame('mismatch', app(PaymentReceiptReader::class)->forPayment($this->freshPayment($order['payment']))->state()->value);
    }

    public function test_the_correction_of_one_of_two_parts_settles_when_the_parts_now_add_up(): void
    {
        $order = $this->bankOrder();
        $a = $this->receive($order, 6000, 'A');
        $this->receive($order, 3000, 'B');
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());

        $this->correct($a, 7000, reference: 'A', reason: 'it was 70');

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(10000, $this->effectiveSum($order));
    }

    // --- after settlement ------------------------------------------------------------------------------------

    private function assertPaymentUntouchedAndNoConfirmation(array $order, array $paymentRowBefore, int $effectiveSumBefore, int $totalRoomBefore, array $eventTypesBefore): void
    {
        $this->assertSame($paymentRowBefore, (array) $this->paymentRow($order['payment']), 'the payment row is byte for byte the same');
        $this->assertSame($effectiveSumBefore, $this->effectiveSum($order));
        $this->assertSame($totalRoomBefore, app(RefundCapGuard::class)->totalRoom($this->freshPayment($order['payment']))->minorValue());
        $this->assertSame([...$eventTypesBefore, 'payment_receipt_corrected'], $this->eventTypes($order), 'exactly one new event, and it is not a payment_confirmed');
        $this->assertSame(['order.payment_receipt_corrected'], $this->hookNames(), 'only the correction hook; no order.payment_confirmed');
    }

    public function test_after_an_exact_settlement_the_day_and_the_reference_can_be_corrected_and_nothing_money_wise_changes(): void
    {
        $staff = $this->actingAsAdministrator();
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 10000, 'REF-TYPO', '2026-09-28');
        $paymentRow = (array) $this->paymentRow($order['payment']);
        $sum = $this->effectiveSum($order);
        $room = app(RefundCapGuard::class)->totalRoom($this->freshPayment($order['payment']))->minorValue();
        $events = $this->eventTypes($order);
        $this->hooks = [];

        $result = $this->correct($receipt, 10000, '2026-09-28', 'REF-FIXED', 'typo in the reference', 'k-ref');

        $this->assertFalse($result->settled(), 'this call settled nothing');
        $this->assertSame('REF-FIXED', $result->receipt()->bankReference());
        $this->assertPaymentUntouchedAndNoConfirmation($order, $paymentRow, $sum, $room, $events);

        $event = DB::table('order_events')->where('type', 'payment_receipt_corrected')->first();
        $this->assertSame('typo in the reference', $event->reason);
        $this->assertSame((string) $staff->id, (string) $event->staff_id);
        $this->assertSame((string) $order['payment']->id(), (string) $event->payment_id);
        $this->assertSame((string) $result->receipt()->id(), (string) $event->payment_receipt_id);

        // The old row stays as the superseded one; the hook carries the new and the superseded receipt.
        $this->assertSame((string) $receipt->id(), $result->receipt()->supersedesReceiptId());
        $this->assertNotNull(DB::table('payment_receipts')->where('id', $receipt->id())->first());
        [$name, $arguments] = $this->hooks[0];
        $this->assertSame((string) $result->receipt()->id(), $arguments[2]->id());
        $this->assertSame((string) $receipt->id(), $arguments[3]->id());
        $this->assertTrue($arguments[1]->isSettled());
    }

    public function test_after_an_exact_settlement_the_day_alone_can_be_corrected(): void
    {
        $order = $this->bankOrder();
        $next = new DateTimeImmutable('2026-09-29 12:00:00');
        $receipt = $this->recorder()->record((string) $order['payment']->id(), $this->eur(10000), '2026-09-29', 'REF', $next)->receipt();
        $paymentRow = (array) $this->paymentRow($order['payment']);
        $sum = $this->effectiveSum($order);
        $room = app(RefundCapGuard::class)->totalRoom($this->freshPayment($order['payment']))->minorValue();
        $events = $this->eventTypes($order);
        $this->hooks = [];

        $result = $this->correct($receipt, 10000, '2026-09-28', 'REF', 'it arrived a day earlier', at: $next);

        $this->assertSame('2026-09-28', $result->receipt()->receivedOn());
        $this->assertSame('REF', $result->receipt()->bankReference());
        $this->assertPaymentUntouchedAndNoConfirmation($order, $paymentRow, $sum, $room, $events);
        $this->assertSame('2026-09-29 12:00:00', $this->paymentRow($order['payment'])->confirmed_at, 'confirmed_at is when it was recorded, and stays so');
    }

    public function test_after_an_accepted_mismatch_the_day_and_the_reference_can_be_corrected_and_the_acceptance_is_untouched(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000, 'REF-TYPO', '2026-09-28');
        $this->recorder()->acceptMismatch((string) $order['payment']->id(), $this->eur(9000), 'agreed by phone', $this->at());
        $paymentRow = (array) $this->paymentRow($order['payment']);
        $this->assertSame(9000, (int) $paymentRow['settled_amount_minor']);
        $sum = $this->effectiveSum($order);
        $room = app(RefundCapGuard::class)->totalRoom($this->freshPayment($order['payment']))->minorValue();
        $this->assertSame(9000, $room);
        $events = $this->eventTypes($order);
        $this->hooks = [];

        $this->correct($receipt, 9000, '2026-09-28', 'REF-FIXED', 'typo in the reference');

        $this->assertPaymentUntouchedAndNoConfirmation($order, $paymentRow, $sum, $room, $events);
        $row = $this->paymentRow($order['payment']);
        $this->assertSame(9000, (int) $row->settled_amount_minor);
        $this->assertSame('agreed by phone', $row->settlement_reason);
    }

    public function test_after_settlement_a_changed_amount_is_refused_by_name_for_an_exact_and_for_an_accepted_payment(): void
    {
        $exact = $this->bankOrder();
        $exactReceipt = $this->receive($exact, 10000);
        $this->assertRefused(PaymentReceiptRefusedException::RECEIPT_AMOUNT_CHANGE_AFTER_SETTLEMENT, $exact, fn () => $this->correct($exactReceipt, 9000), 'amount');

        $accepted = $this->bankOrder();
        $acceptedReceipt = $this->receive($accepted, 9000);
        $this->recorder()->acceptMismatch((string) $accepted['payment']->id(), $this->eur(9000), 'agreed', $this->at());
        $this->hooks = [];
        $this->assertRefused(PaymentReceiptRefusedException::RECEIPT_AMOUNT_CHANGE_AFTER_SETTLEMENT, $accepted, fn () => $this->correct($acceptedReceipt, 10000, reference: 'NEW'), 'amount');

        $this->assertSame(9000, $this->freshPayment($accepted['payment'])->settledAmount()->minorValue());
    }

    public function test_a_legacy_settled_payment_has_no_receipt_to_correct(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'bank_transfer');
        $paymentId = (string) $order['payment']->id();

        $this->assertSame([], app(PaymentReceiptRepository::class)->findEffectiveByPaymentId($paymentId));

        try {
            $this->recorder()->correct('999999', $this->eur(10000), '2026-09-28', 'REF', 'reason', $this->at());
            $this->fail('there is nothing to correct.');
        } catch (PaymentReceiptRefusedException $exception) {
            $this->assertSame(PaymentReceiptRefusedException::RECEIPT_UNKNOWN, $exception->reason);
        }

        $this->assertSame(0, DB::table('payment_receipts')->count(), 'no receipt is invented');
    }

    // --- which receipts, which payments ----------------------------------------------------------------------

    public function test_a_superseded_receipt_cannot_be_corrected(): void
    {
        $order = $this->bankOrder();
        $first = $this->receive($order, 9000);
        $this->correct($first, 8000, reason: 'first fix');

        $this->assertRefused(PaymentReceiptRefusedException::RECEIPT_NOT_EFFECTIVE, $order, fn () => $this->correct($first, 7000, reason: 'again the old one'));
    }

    public function test_an_unknown_receipt_is_refused_by_name(): void
    {
        $order = $this->bankOrder();

        $this->assertRefused(PaymentReceiptRefusedException::RECEIPT_UNKNOWN, $order, fn () => $this->recorder()->correct('424242', $this->eur(100), '2026-09-28', 'REF', 'reason', $this->at()));
    }

    public function test_a_receipt_of_a_non_bank_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder(method: 'cash_on_delivery');
        $receipt = $this->appendReceipt($order['payment'], 9000);

        $this->assertRefused(PaymentReceiptRefusedException::NOT_BANK_TRANSFER, $order, fn () => $this->correct($receipt, 8000));
    }

    public function test_a_receipt_of_a_voided_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->appendReceipt($order['payment'], 9000);
        $order['payment']->void(new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        $this->assertRefused(PaymentReceiptRefusedException::PAYMENT_VOIDED, $order, fn () => $this->correct($receipt, 8000));
    }

    public function test_a_correction_that_changes_nothing_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000, 'REF', '2026-09-28');

        $this->assertRefused(PaymentReceiptRefusedException::NOTHING_TO_CORRECT, $order, fn () => $this->correct($receipt, 9000, '2026-09-28', 'REF', 'only the reason differs'));
        // A reference that differs only by surrounding whitespace is the same reference.
        $this->assertRefused(PaymentReceiptRefusedException::NOTHING_TO_CORRECT, $order, fn () => $this->correct($receipt, 9000, '2026-09-28', "  REF\u{00A0}", 'whitespace only'));
    }

    // --- the limits ------------------------------------------------------------------------------------------

    public function test_the_51st_row_is_refused_and_the_effective_limit_is_not_rechecked(): void
    {
        $order = $this->bankOrder();

        // 49 rows in one chain, one effective: a correction is the 50th row and is accepted...
        $previous = null;
        for ($i = 1; $i <= 49; $i++) {
            $previous = $this->appendReceipt($order['payment'], 100, 'CHAIN-'.$i, supersedes: $previous);
        }

        $fiftieth = $this->correct($previous, 200, reference: 'CHAIN-50', reason: 'fiftieth row');
        $this->assertSame(50, app(PaymentReceiptRepository::class)->countRows((string) $order['payment']->id()));

        // ...and the next one is the 51st.
        $this->assertRefused(PaymentReceiptRefusedException::TOO_MANY_RECEIPT_ROWS, $order, fn () => $this->correct($fiftieth->receipt(), 300, reference: 'CHAIN-51', reason: 'one too many'));
    }

    public function test_a_correction_at_twenty_effective_receipts_is_accepted_because_it_never_raises_the_effective_count(): void
    {
        $order = $this->bankOrder();
        $receipts = [];
        for ($i = 1; $i <= 20; $i++) {
            $receipts[] = $this->appendReceipt($order['payment'], 100, 'R-'.$i);
        }

        $this->correct($receipts[7], 150, reference: 'R-8', reason: 'it was 1.50');

        $this->assertCount(20, app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id()));
        $this->assertSame(19 * 100 + 150, $this->effectiveSum($order));
    }

    public function test_a_superseded_receipt_is_ignored_by_the_effective_sum_and_by_the_settlement_check(): void
    {
        $order = $this->bankOrder();
        $a = $this->receive($order, 6000, 'A');
        $b = $this->receive($order, 3000, 'B');
        $this->correct($a, 5000, reference: 'A');

        $this->assertSame(8000, $this->effectiveSum($order), '5000 + 3000, not 6000 + 5000 + 3000');
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());

        // A further 2000 completes 5000 + 3000 + 2000; the superseded 6000 does not push it over.
        $this->receive($order, 2000, 'C');
        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame((string) $b->id(), (string) DB::table('payment_receipts')->where('bank_reference', 'B')->value('id'));
    }

    // --- validation exactly like record() --------------------------------------------------------------------

    public function test_the_new_values_are_validated_exactly_like_a_new_receipt(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);

        $this->assertRefused(PaymentReceiptRefusedException::DAY_IN_FUTURE, $order, fn () => $this->correct($receipt, 9000, '2026-09-29', 'REF'), 'received_on');
        $this->assertRefused(PaymentReceiptRefusedException::DAY_BEFORE_PLACEMENT, $order, fn () => $this->correct($receipt, 9000, '2026-09-27', 'REF'), 'received_on');

        foreach (['', '2026-9-28', '2026-02-30', '28.09.2026', "2026-09-28\n", str_repeat('9', 5000)] as $bad) {
            $this->assertRefused(PaymentReceiptRefusedException::DAY_MALFORMED, $order, fn () => $this->correct($receipt, 9000, $bad, 'REF'), 'received_on');
        }

        $this->assertRefused(PaymentReceiptRefusedException::AMOUNT_TOO_LARGE, $order, fn () => $this->recorder()->correct((string) $receipt->id(), Money::fromDecimal('1000000000.00', 'EUR'), '2026-09-28', 'REF', 'reason', $this->at()), 'amount');
        $this->assertRefused(PaymentReceiptRefusedException::AMOUNT_NOT_POSITIVE, $order, fn () => $this->correct($receipt, 0), 'amount');
        $this->assertRefused(PaymentReceiptRefusedException::CURRENCY_MISMATCH, $order, fn () => $this->recorder()->correct((string) $receipt->id(), Money::fromMinorUnits(9000, 'USD'), '2026-09-28', 'REF', 'reason', $this->at()), 'amount');

        foreach (['', "\u{00A0}", "\t\n"] as $blank) {
            $this->assertRefused(PaymentReceiptRefusedException::REFERENCE_BLANK, $order, fn () => $this->correct($receipt, 9000, reference: $blank), 'bank_reference');
        }

        $this->assertRefused(PaymentReceiptRefusedException::REFERENCE_TOO_LONG, $order, fn () => $this->correct($receipt, 9000, reference: str_repeat('A', 65)), 'bank_reference');
        $this->assertRefused(PaymentReceiptRefusedException::REFERENCE_INVALID, $order, fn () => $this->correct($receipt, 9000, reference: "A\nB"), 'bank_reference');
    }

    public function test_the_reason_is_mandatory_at_most_255_characters_and_one_line_of_visible_text(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);

        foreach (['', '   ', "\u{00A0}", "\u{200B}"] as $blank) {
            $this->assertRefused(PaymentReceiptRefusedException::REASON_BLANK, $order, fn () => $this->correct($receipt, 8000, reason: $blank), 'reason');
        }

        $this->assertRefused(PaymentReceiptRefusedException::REASON_TOO_LONG, $order, fn () => $this->correct($receipt, 8000, reason: str_repeat('x', 256)), 'reason');

        foreach (["a\nb", "a\0b", "a\u{202E}b", "a\xC3\x28b"] as $bad) {
            $this->assertRefused(PaymentReceiptRefusedException::REASON_INVALID, $order, fn () => $this->correct($receipt, 8000, reason: $bad), 'reason');
        }

        $result = $this->correct($receipt, 8000, reason: str_repeat('Я', 255));
        $this->assertSame(255, mb_strlen((string) DB::table('order_events')->where('type', 'payment_receipt_corrected')->value('reason')));
        $this->assertNotNull($result->receipt()->id());
    }

    public function test_the_days_are_store_timezone_days_in_europe_sofia(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
        $order = $this->bankOrder();
        DB::table('orders')->where('id', $order['orderId'])->update(['placed_at' => '2026-09-27 21:30:00']); // 2026-09-28 00:30 in Sofia
        $receipt = $this->receive($order, 9000, 'REF', '2026-09-28');

        $this->assertRefused(PaymentReceiptRefusedException::DAY_BEFORE_PLACEMENT, $order, fn () => $this->correct($receipt, 9000, '2026-09-27', 'REF'), 'received_on');

        // Recorded 2026-09-28 21:30 UTC = the 29th in Sofia: the 29th is today, the 30th the future.
        $late = new DateTimeImmutable('2026-09-28 21:30:00');
        $this->assertRefused(PaymentReceiptRefusedException::DAY_IN_FUTURE, $order, fn () => $this->correct($receipt, 9000, '2026-09-30', 'REF', at: $late), 'received_on');

        $corrected = $this->correct($receipt, 9000, '2026-09-29', 'REF', at: $late);
        $this->assertSame('2026-09-29', $corrected->receipt()->receivedOn(), 'never shifted');
    }

    // --- idempotency -----------------------------------------------------------------------------------------

    public function test_the_same_key_twice_is_one_correction_one_event_and_one_hook(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);
        $this->hooks = [];

        $first = $this->correct($receipt, 8000, key: 'k-1');
        $again = $this->correct($receipt, 8000, key: 'k-1');

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($again->wasReplay());
        $this->assertSame($first->receipt()->id(), $again->receipt()->id(), 'the first result');
        $this->assertSame(2, DB::table('payment_receipts')->count());
        $this->assertSame(1, DB::table('order_events')->where('type', 'payment_receipt_corrected')->count());
        $this->assertSame(['order.payment_receipt_corrected'], $this->hookNames());
    }

    public function test_a_replay_of_a_settling_correction_returns_the_first_result_although_the_payment_is_now_settled(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);

        $first = $this->correct($receipt, 10000, key: 'k-settle');
        $this->hooks = [];
        $again = $this->correct($receipt, 10000, key: 'k-settle');

        $this->assertTrue($first->settled());
        $this->assertTrue($again->wasReplay());
        $this->assertSame($first->receipt()->id(), $again->receipt()->id());
        $this->assertSame(2, DB::table('payment_receipts')->count());
        $this->assertSame([], $this->hooks);
    }

    public function test_the_same_key_with_other_contents_is_refused_and_writes_nothing(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);
        $this->correct($receipt, 8000, key: 'k-2');
        $before = $this->snapshot($order);
        $this->hooks = [];

        foreach ([
            fn () => $this->correct($receipt, 7000, key: 'k-2'),
            fn () => $this->correct($receipt, 8000, reference: 'OTHER', key: 'k-2'),
            fn () => $this->correct($receipt, 8000, reason: 'another reason', key: 'k-2'),
        ] as $call) {
            try {
                $call();
                $this->fail('the same key with other contents must be refused.');
            } catch (OperationKeyReusedException) {
                // expected
            }
        }

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame([], $this->hooks);
    }

    public function test_a_bad_operation_key_or_empty_id_is_an_invalid_argument(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);

        foreach ([str_repeat('k', 65), '', '   '] as $key) {
            try {
                $this->correct($receipt, 8000, key: $key);
                $this->fail('bad key');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        try {
            $this->recorder()->correct('', $this->eur(100), '2026-09-28', 'REF', 'reason', $this->at());
            $this->fail('empty id');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_a_failure_while_settling_rolls_the_correction_back(): void
    {
        $order = $this->bankOrder();
        $receipt = $this->receive($order, 9000);
        // Another payment of the same order already holds the money: the confirmer's courtesy check refuses the settlement.
        $other = \EasyCo\Payment\Payment::create($order['orderId'], 'bank_transfer', $this->eur(10000), \EasyCo\Payment\Enums\PaymentStatus::PENDING);
        $other->recordAttemptResult(\EasyCo\Payment\Enums\PaymentStatus::CAPTURED, 'ref', null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($other);
        $before = $this->snapshot($order);
        $this->hooks = [];

        try {
            $this->correct($receipt, 10000);
            $this->fail('a second settled payment on the order must refuse the settlement.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame($before, $this->snapshot($order), 'the new receipt and its event rolled back');
        $this->assertSame([], $this->hooks);
    }
}
