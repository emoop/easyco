<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\PaymentReceiptRefusedException as Refused;
use App\Settings\StoreTimezone;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a bank transfer the merchant saw arrive (refunds R4a-2, shipping-domain-design.md §7.2.20 §3,
 * §5). It writes ONE receipt row and ONE history event (`payment_receipt_recorded`; reason = the bank
 * reference), and — only when the receipts now add up to EXACTLY the expected amount — settles the
 * payment through OrderPaymentConfirmer (the one implementation of settling), which writes the usual
 * `payment_confirmed` event. A transfer that does not add up (short or over) is recorded and the payment
 * stays UNSETTLED: R9 keeps blocking shipping, and cancel / return / edit are refused by name
 * (PaymentReceiptUnreconciledException) while the money is in hand and not reconciled.
 *
 * ONE TRANSACTION, THE SAME SHAPE AS MoneyOnlyRefunder: read the payment (to find the order), LOCK THE
 * ORDER, replay by operation key (same key and same contents -> the first result, nothing written, no
 * hook; other contents -> OperationKeyReusedException; the UNIQUE (order_id, operation_key) is the
 * backstop, with the same one retry), then validate, then write. The payment is read AGAIN under the
 * lock, so every decision is made on what is true while no one else can move this order's money.
 *
 * REFUSED by name before anything is written (PaymentReceiptRefusedException, translated, with the form
 * field it belongs to): a payment that is not a bank transfer, or is settled, voided or unanswered; an
 * amount that is not positive, has more than 9 integer digits (MoneyInput's limit) or another currency;
 * a received day that is not a real 'Y-m-d', is after today or before the order's placement day (both
 * STORE-timezone calendar days, compared as text, never shifted); a reference that is blank (also only
 * non-ASCII whitespace), longer than 64 characters or holds control characters; a 21st effective
 * receipt or a 51st row (superseded ones counted).
 *
 * HOOKS, after the commit, never for a replay or a refusal: `order.payment_receipt_recorded (Order,
 * Payment, PaymentReceipt)`, and — when it settled — the existing `order.payment_confirmed (Payment)`.
 *
 * NO PERMISSION CHECK here, like every app service: the action that calls this is what is authorized
 * (ORDER_MANAGE, §5). The staff member is resolved inside, from the panel guard.
 */
final class PaymentReceiptRecorder
{
    /** Effective receipts per payment, so every sum stays far below PHP_INT_MAX (§2). */
    public const MAX_EFFECTIVE_RECEIPTS = 20;

    /** Rows per payment, superseded ones counted, so a correction chain stays bounded (§2). */
    public const MAX_RECEIPT_ROWS = 50;

    /** Bytes of a reference looked at at all: a form field is attacker-controllable text. */
    private const MAX_REFERENCE_BYTES = 1024;

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
        private readonly PaymentReceiptRepository $receipts,
        private readonly PaymentReceiptReader $reader,
        private readonly OrderPaymentConfirmer $confirmer,
        private readonly OrderEventRecorder $events,
        private readonly StoreTimezone $storeTimezone,
        private readonly PanelStaffActor $staffActor,
    ) {
    }

    /**
     * @param  string  $receivedOn  the store-timezone calendar day the money arrived, 'Y-m-d'
     * @param  DateTimeImmutable  $occurredAt  the instant of recording (UTC): it is the receipt's recorded_at, the events' time and, on a match, confirmed_at
     *
     * @throws Refused the reasons above
     * @throws OperationKeyReusedException the same key with other contents
     * @throws InvalidArgumentException an unknown payment id, a payment whose order does not exist, an operation key that is blank or longer than 64
     */
    public function record(
        string $paymentId,
        Money $amount,
        string $receivedOn,
        string $reference,
        DateTimeImmutable $occurredAt,
        ?string $operationKey = null,
    ): PaymentReceiptResult {
        if (trim($paymentId) === '') {
            throw new InvalidArgumentException('PaymentReceiptRecorder: paymentId must not be empty.');
        }

        if ($operationKey !== null && (trim($operationKey) === '' || strlen($operationKey) > 64)) {
            throw new InvalidArgumentException('PaymentReceiptRecorder: an operation key is 1 to 64 characters.');
        }

        // The shape checks depend on the request alone, so a repeat is refused identically; they run
        // before the lock. The checks that need the payment or the order run under it.
        $reference = self::cleanReference($reference);
        self::assertAmountShape($amount);

        return $this->perform($paymentId, $amount, $receivedOn, $reference, $occurredAt, $operationKey, false);
    }

    private function perform(string $paymentId, Money $amount, string $receivedOn, string $reference, DateTimeImmutable $occurredAt, ?string $operationKey, bool $afterDuplicateKey): PaymentReceiptResult
    {
        $hash = $operationKey !== null
            ? RefundOperationFingerprint::forReceipt($paymentId, $amount->minorValue(), $amount->currency()->code(), $receivedOn, $reference)
            : null;

        // The payment first, because the order to lock is only known from it — and OUTSIDE the transaction,
        // on purpose: a plain read inside it would open MySQL's consistent snapshot BEFORE the lock, and a
        // receipt committed by the call that held the lock would then be invisible to every read below. A
        // payment never changes its order, so this read decides nothing but which order to lock.
        $orderId = ($this->payments->findById($paymentId)
            ?? throw new InvalidArgumentException("PaymentReceiptRecorder: no payment exists with id \"{$paymentId}\"."))->orderId();

        try {
            $result = DB::transaction(function () use ($paymentId, $orderId, $amount, $receivedOn, $reference, $occurredAt, $operationKey, $hash): array {
                // THE LOCK, as the first statement: the single serialisation point (§7.2.3).
                $order = $this->orders->findByIdForUpdate($orderId)
                    ?? throw new InvalidArgumentException("PaymentReceiptRecorder: payment \"{$paymentId}\" belongs to order \"{$orderId}\", which does not exist.");

                if ($operationKey !== null) {
                    $prior = DB::table('order_events')->where('order_id', $order->id())->where('operation_key', $operationKey)->first();

                    if ($prior !== null) {
                        if (! hash_equals((string) $prior->operation_payload_hash, (string) $hash)) {
                            throw new OperationKeyReusedException();
                        }

                        return ['replayed' => true, 'receiptId' => (string) $prior->payment_receipt_id, 'paymentId' => (string) $prior->payment_id];
                    }
                }

                // Under the lock, the payment as it truly is now.
                $payment = $this->payments->findById($paymentId)
                    ?? throw new InvalidArgumentException("PaymentReceiptRecorder: no payment exists with id \"{$paymentId}\".");

                $this->assertRecordable($payment);
                $this->assertAmountMatchesPayment($amount, $payment);
                $this->assertDay($receivedOn, $order->placedAt(), $occurredAt);

                $status = $this->reader->forPayment($payment);

                if ($status->effectiveCount() + 1 > self::MAX_EFFECTIVE_RECEIPTS) {
                    throw new Refused(Refused::TOO_MANY_EFFECTIVE_RECEIPTS, ['max' => self::MAX_EFFECTIVE_RECEIPTS]);
                }

                if ($this->receipts->countRows($paymentId) + 1 > self::MAX_RECEIPT_ROWS) {
                    throw new Refused(Refused::TOO_MANY_RECEIPT_ROWS, ['max' => self::MAX_RECEIPT_ROWS]);
                }

                $matches = $status->withReceipt($amount)->matches();

                $staff = $this->staffActor->current();
                $receipt = PaymentReceipt::create(
                    $paymentId,
                    $amount,
                    $receivedOn,
                    $reference,
                    $occurredAt,
                    $staff !== null ? (string) $staff->id : null,
                );
                $this->receipts->save($receipt);

                $this->events->record(
                    orderId: $order->id(),
                    type: OrderEventType::PAYMENT_RECEIPT_RECORDED,
                    fromStatus: null,
                    toStatus: null,
                    reason: $reference,
                    transactionId: null,
                    occurredAt: $occurredAt,
                    operationKey: $operationKey,
                    operationPayloadHash: $hash,
                    paymentId: $paymentId,
                    paymentReceiptId: $receipt->id(),
                );

                // The receipts add up to exactly what was expected: settle through the ONE implementation.
                // confirmed_at is the instant of recording, as it always was; settled_amount stays NULL.
                $payment = $matches
                    ? $this->confirmer->confirmReconciledWithinOpenTransaction($paymentId, $occurredAt)
                    : $payment;

                return ['replayed' => false, 'order' => $order, 'payment' => $payment, 'receipt' => $receipt, 'settled' => $matches];
            });
        } catch (QueryException $exception) {
            // The UNIQUE (order_id, operation_key) is the backstop behind the lock: a concurrent submission
            // got its row in first, so this call is a repeat — run it once more and it replays.
            if ($operationKey !== null && ! $afterDuplicateKey && self::isDuplicateKey($exception)) {
                return $this->perform($paymentId, $amount, $receivedOn, $reference, $occurredAt, $operationKey, true);
            }

            throw $exception;
        }

        if ($result['replayed']) {
            return PaymentReceiptResult::replayed(
                $this->receipts->findById($result['receiptId']),
                $this->payments->findById($result['paymentId']),
            );
        }

        Hook::fire('order.payment_receipt_recorded', $result['order'], $result['payment'], $result['receipt']);

        if ($result['settled']) {
            Hook::fire('order.payment_confirmed', $result['payment']);
        }

        return PaymentReceiptResult::performed($result['receipt'], $result['payment'], $result['settled']);
    }

    /**
     * Only a confirmable bank transfer takes a receipt: pending, answered, unconfirmed, unvoided. Refused by name,
     * the most specific reason first.
     */
    private function assertRecordable(Payment $payment): void
    {
        if ($payment->method() !== PaymentReceiptReader::BANK_TRANSFER) {
            throw new Refused(Refused::NOT_BANK_TRANSFER);
        }

        if ($payment->isSettled()) {
            throw new Refused(Refused::PAYMENT_SETTLED);
        }

        if ($payment->isVoided()) {
            throw new Refused(Refused::PAYMENT_VOIDED);
        }

        if ($payment->attemptedAt() === null) {
            throw new Refused(Refused::PAYMENT_UNANSWERED);
        }

        if (! $payment->isConfirmable()) {
            throw new Refused(Refused::PAYMENT_NOT_CONFIRMABLE);
        }
    }

    /** Positive, and no more integer digits than MoneyInput allows: every sum the service adds stays far below PHP_INT_MAX. */
    private static function assertAmountShape(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new Refused(Refused::AMOUNT_NOT_POSITIVE);
        }

        if ($amount->minorValue() >= 10 ** (MoneyInput::MAX_INTEGER_DIGITS + $amount->currency()->decimalPlaces())) {
            throw new Refused(Refused::AMOUNT_TOO_LARGE, ['digits' => MoneyInput::MAX_INTEGER_DIGITS]);
        }
    }

    private function assertAmountMatchesPayment(Money $amount, Payment $payment): void
    {
        if (! $amount->currency()->equals($payment->amount()->currency())) {
            throw new Refused(Refused::CURRENCY_MISMATCH, ['currency' => $payment->amount()->currency()->code()]);
        }
    }

    /**
     * A real 'Y-m-d' calendar day of the STORE timezone, not after today and not before the order's
     * placement day. Both bounds are inclusive, compared as text, and the day is never shifted.
     */
    private function assertDay(string $receivedOn, DateTimeImmutable $placedAt, DateTimeImmutable $occurredAt): void
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedOn) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $receivedOn) : false;

        if ($day === false || $day->format('Y-m-d') !== $receivedOn) {
            throw new Refused(Refused::DAY_MALFORMED);
        }

        if ($receivedOn > $this->storeTimezone->dayOf($occurredAt)) {
            throw new Refused(Refused::DAY_IN_FUTURE);
        }

        if ($receivedOn < $this->storeTimezone->dayOf($placedAt)) {
            throw new Refused(Refused::DAY_BEFORE_PLACEMENT);
        }
    }

    /**
     * The bank reference as it is stored: trimmed of ordinary AND non-ASCII whitespace (a no-break space,
     * a zero-width space), 1 to 64 characters, one line of printable text. Control and invisible
     * formatting characters inside it are refused — a reference is what the merchant reads against the
     * bank statement, and nothing invisible belongs in it.
     */
    private static function cleanReference(string $reference): string
    {
        if (strlen($reference) > self::MAX_REFERENCE_BYTES) {
            throw new Refused(Refused::REFERENCE_TOO_LONG, ['max' => PaymentReceipt::MAX_REFERENCE_LENGTH]);
        }

        if (! mb_check_encoding($reference, 'UTF-8')) {
            throw new Refused(Refused::REFERENCE_INVALID);
        }

        $edge = '[\p{Z}\s\x{FEFF}\x{200B}-\x{200D}\x{2060}]+';
        $clean = preg_replace('/^'.$edge.'|'.$edge.'$/u', '', $reference);

        if ($clean === null) {
            throw new Refused(Refused::REFERENCE_INVALID);
        }

        if ($clean === '') {
            throw new Refused(Refused::REFERENCE_BLANK);
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', $clean) === 1) {
            throw new Refused(Refused::REFERENCE_INVALID);
        }

        if (mb_strlen($clean) > PaymentReceipt::MAX_REFERENCE_LENGTH) {
            throw new Refused(Refused::REFERENCE_TOO_LONG, ['max' => PaymentReceipt::MAX_REFERENCE_LENGTH]);
        }

        return $clean;
    }

    /** SQLSTATE 23000 + the driver's duplicate-key code (MySQL 1062, SQLite 19) — never the message (CLAUDE.md rule 3). */
    private static function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' && in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true);
    }
}
