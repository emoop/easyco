<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Maps the Payment entity onto `payments`.
 *
 * NO CUSTOM UNIQUE-VIOLATION HANDLING for either generated-column
 * constraint — captured_order_id (payment-domain-design.md §5.1) or
 * settled_order_id (order-lifecycle-design.md §4.4), which covers the
 * offline confirmation captured_order_id deliberately does not. A genuine
 * violation of either surfaces as a raw
 * Illuminate\Database\QueryException: these are database-engine-level
 * safety nets that should essentially never fire if the calling code is
 * well-behaved, and catching/wrapping one is a decision for whoever writes
 * the operation that can hit it, not this persistence layer.
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
        $model->attempted_at = $payment->attemptedAt();
        $model->confirmed_at = $payment->confirmedAt();
        $model->voided_at = $payment->voidedAt();

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

    /**
     * The settled half of findByOrderId() — see the contract's own docblock
     * for what it is for and why the predicate is written out here instead of
     * read from the generated settled_order_id column.
     *
     * One row at most: pay_settled_order_unique makes the database refuse a
     * second settled row per order, so "which one" is never a question. The
     * ordering is therefore absent deliberately — it could only ever pick
     * between rows that cannot both exist.
     */
    public function findSettledForOrder(string $orderId): ?Payment
    {
        $model = PaymentModel::where('order_id', $orderId)
            ->whereNull('voided_at')
            ->where(function (Builder $query): void {
                $query->where('status', PaymentStatus::CAPTURED->value)
                    ->orWhereNotNull('confirmed_at');
            })
            ->first();

        return $model !== null ? $this->toDomainPayment($model) : null;
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
            attemptedAt: $model->attempted_at,
            confirmedAt: $model->confirmed_at,
            voidedAt: $model->voided_at,
        );
    }
}
