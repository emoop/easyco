<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;

/**
 * Maps the Payment entity onto `payments`.
 *
 * NO CUSTOM UNIQUE-VIOLATION HANDLING for the captured_order_id
 * generated-column constraint (payment-domain-design.md §5.1) —
 * deliberately. A genuine violation surfaces as a raw
 * Illuminate\Database\QueryException: this is a database-engine-level
 * safety net that should essentially never fire if the calling code is
 * well-behaved, and catching/wrapping it is a decision for whoever
 * writes the actual capture-orchestration logic later, not this
 * persistence-layer prompt.
 */
final class EloquentPaymentRepository implements PaymentRepository
{
    public function save(Payment $payment): void
    {
        $model = $payment->id() !== null
            ? PaymentModel::findOrFail($payment->id())
            : new PaymentModel();

        $model->order_id = $payment->orderId();
        $model->method = $payment->method();
        $model->amount_minor = $payment->amount()->minorValue();
        $model->amount_currency = $payment->amount()->currency()->code();
        $model->status = $payment->status()->value;
        $model->provider_reference = $payment->providerReference();
        $model->failure_reason = $payment->failureReason();

        $model->save();

        if ($payment->id() === null) {
            $payment->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Payment
    {
        $model = PaymentModel::find($id);

        return $model !== null ? $this->toDomainPayment($model) : null;
    }

    /** @return Payment[] */
    public function findByOrderId(string $orderId): array
    {
        return PaymentModel::where('order_id', $orderId)
            ->get()
            ->map(fn (PaymentModel $model) => $this->toDomainPayment($model))
            ->all();
    }

    private function toDomainPayment(PaymentModel $model): Payment
    {
        return Payment::reconstituteFromStorage(
            id: (string) $model->id,
            orderId: $model->order_id,
            method: $model->method,
            amount: Money::fromMinorUnits($model->amount_minor, $model->amount_currency),
            status: PaymentStatus::from($model->status),
            providerReference: $model->provider_reference,
            failureReason: $model->failure_reason,
        );
    }
}
