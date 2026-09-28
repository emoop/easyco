<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;

/**
 * Maps the PaymentRefund entity onto `payment_refunds`. No
 * unique-constraint collision handling needed — nothing about this
 * entity is unique.
 */
final class EloquentPaymentRefundRepository implements PaymentRefundRepository
{
    public function save(PaymentRefund $refund): void
    {
        $model = $refund->id() !== null
            ? PaymentRefundModel::findOrFail($refund->id())
            : new PaymentRefundModel();

        $model->payment_id = $refund->paymentId();
        $model->amount_minor = $refund->amount()->minorValue();
        $model->amount_currency = $refund->amount()->currency()->code();
        $model->reason = $refund->reason();
        $model->refunded_by = $refund->refundedBy();
        $model->status = $refund->status()->value;
        $model->failure_reason = $refund->failureReason();

        $model->save();

        if ($refund->id() === null) {
            $refund->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?PaymentRefund
    {
        $model = PaymentRefundModel::find($id);

        return $model !== null ? $this->toDomainPaymentRefund($model) : null;
    }

    /** @return PaymentRefund[] */
    public function findByPaymentId(string $paymentId): array
    {
        return PaymentRefundModel::where('payment_id', $paymentId)
            ->get()
            ->map(fn (PaymentRefundModel $model) => $this->toDomainPaymentRefund($model))
            ->all();
    }

    /**
     * One SUM over the payment's own rows — see the contract's docblock for
     * why this is a query rather than a get() and an add-up. `sum()` returns
     * 0 (not null) when nothing matches, which is exactly the "nothing
     * refunded yet" answer R8(a)'s cap needs; the cast is for the driver
     * returning the total as a string.
     *
     * The currency is the caller's (the payment's own) and is never inferred
     * from the rows: a refund is constructed from the payment it corrects,
     * so every row of one payment is in that payment's currency.
     */
    public function sumCompletedForPayment(string $paymentId, string $currency): Money
    {
        $sum = PaymentRefundModel::where('payment_id', $paymentId)
            ->where('status', PaymentRefundStatus::COMPLETED->value)
            ->sum('amount_minor');

        return Money::fromMinorUnits((int) $sum, $currency);
    }

    private function toDomainPaymentRefund(PaymentRefundModel $model): PaymentRefund
    {
        return PaymentRefund::reconstituteFromStorage(
            id: (string) $model->id,
            paymentId: $model->payment_id,
            amount: Money::fromMinorUnits($model->amount_minor, $model->amount_currency),
            reason: $model->reason,
            refundedBy: $model->refunded_by,
            status: PaymentRefundStatus::from($model->status),
            failureReason: $model->failure_reason,
        );
    }
}
