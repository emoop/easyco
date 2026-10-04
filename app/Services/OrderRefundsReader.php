<?php

namespace App\Services;

use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Illuminate\Support\Facades\DB;

/**
 * The read model of an order's refunds for the admin View page (refunds R2b,
 * shipping-domain-design.md §7.2.5, §7.2.17): one row per refund, newest first,
 * and the four figures. Raw rows on purpose, like OrderAdminReader: a legacy refund
 * (no breakdown parts beyond the goods, no staff, a mapped note) must render, not
 * crash a domain constructor, and the recorded date lives only in the table.
 *
 * THE FOUR FIGURES, exactly as §7.2.5 words them:
 *  - paid in:               the amount of the order's settled payment (0 when none has settled);
 *  - refunded and paid out: the total of the refunds in state PAID_OUT;
 *  - refunded, still owed:  the total of the refunds in state OWED;
 *  - still refundable:      paid in minus ALL counting refunds (OWED, PAID_OUT, REQUESTED, COMPLETED).
 * The online states (REQUESTED, COMPLETED) have no home in the first two figures in
 * §7.2.5; none exists yet, and they are counted only in "still refundable", as written.
 *
 * Money is returned as integer minor units in the order's currency.
 */
final class OrderRefundsReader
{
    public const LEGACY_NOTE = 'legacy: recorded before the owed/paid model';

    public function __construct(private readonly PaymentRepository $payments)
    {
    }

    /**
     * @return array{
     *   currency: string,
     *   figures: array{paid_in: int, paid_out: int, owed: int, still_refundable: int},
     *   rows: list<array<string, mixed>>
     * }
     */
    public function forOrder(string $orderId, string $currency): array
    {
        $refunds = DB::table('payment_refunds')->where('order_id', $orderId)->orderByDesc('id')->get();

        // An order with no refund is the common case, and the page asks on every render: one query, nothing else.
        if ($refunds->isEmpty()) {
            return ['currency' => $currency, 'figures' => ['paid_in' => 0, 'paid_out' => 0, 'owed' => 0, 'still_refundable' => 0], 'rows' => []];
        }

        $paidIn = 0;

        foreach ($this->payments->findByOrderId($orderId) as $payment) {
            if ($payment->isSettled()) {
                $paidIn += $payment->amount()->minorValue();
            }
        }

        $staffIds = $refunds->flatMap(static fn ($r): array => [$r->refunded_by, $r->paid_out_by, $r->cancelled_by])->filter()->unique()->values()->all();
        $names = $staffIds === [] ? [] : StaffModel::query()->whereIn('id', $staffIds)->pluck('name', 'id')->all();
        $staff = static fn (?string $id): ?string => $id === null || $id === '' ? null : ($names[$id] ?? null);

        $paidOut = 0;
        $owed = 0;
        $counting = 0;
        $rows = [];

        foreach ($refunds as $refund) {
            $status = PaymentRefundStatus::from($refund->status);
            $amount = (int) $refund->amount_minor;

            if ($status === PaymentRefundStatus::PAID_OUT) {
                $paidOut += $amount;
            }

            if ($status === PaymentRefundStatus::OWED) {
                $owed += $amount;
            }

            if ($status->counts()) {
                $counting += $amount;
            }

            $isLegacy = $status === PaymentRefundStatus::PAID_OUT && $refund->paid_out_note === self::LEGACY_NOTE;

            $rows[] = [
                'id' => (string) $refund->id,
                'status' => $status->value,
                'is_legacy' => $isLegacy,
                'channel' => (string) $refund->channel,
                'goods' => (int) $refund->goods_minor,
                'shipping' => (int) $refund->shipping_minor,
                'adjustment' => (int) $refund->adjustment_minor,
                'deduction' => (int) $refund->deduction_minor,
                'deduction_reason' => $refund->deduction_reason,
                'total' => $amount,
                'recorded_at' => $refund->created_at,
                'recorded_by' => $staff($refund->refunded_by),
                'reason' => $refund->reason,
                'paid_out_at' => $refund->paid_out_at,
                'paid_out_reference' => $refund->paid_out_reference,
                'paid_out_note' => $isLegacy ? null : $refund->paid_out_note,
                'paid_out_by' => $staff($refund->paid_out_by),
                'cancelled_at' => $refund->cancelled_at,
                'cancelled_reason' => $refund->cancelled_reason,
                'cancelled_by' => $staff($refund->cancelled_by),
            ];
        }

        return [
            'currency' => $currency,
            'figures' => [
                'paid_in' => $paidIn,
                'paid_out' => $paidOut,
                'owed' => $owed,
                'still_refundable' => $paidIn - $counting,
            ],
            'rows' => $rows,
        ];
    }
}
