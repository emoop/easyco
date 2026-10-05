<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Maps PaymentReceipt onto `payment_receipts`. Append-only: save() only ever INSERTs, and nothing
 * here updates or deletes. Every value reaches the database through bindings.
 */
final class EloquentPaymentReceiptRepository implements PaymentReceiptRepository
{
    public function save(PaymentReceipt $receipt): void
    {
        if ($receipt->id() !== null) {
            throw new LogicException('PaymentReceipt is append-only: a stored receipt is never written again; a correction is a new receipt.');
        }

        $model = new PaymentReceiptModel();
        $model->payment_id = $receipt->paymentId();
        $model->amount_minor = $receipt->amount()->minorValue();
        $model->amount_currency = $receipt->amount()->currency()->code();
        $model->received_on = $receipt->receivedOn();
        $model->bank_reference = $receipt->bankReference();
        $model->supersedes_receipt_id = $receipt->supersedesReceiptId();
        $model->recorded_by = $receipt->recordedBy();
        $model->recorded_at = $receipt->recordedAt();
        $model->save();

        $receipt->assignId((string) $model->id);
    }

    public function findById(string $id): ?PaymentReceipt
    {
        $model = PaymentReceiptModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return list<PaymentReceipt> */
    public function findEffectiveByPaymentId(string $paymentId): array
    {
        return $this->effective($paymentId)
            ->orderBy('payment_receipts.id')
            ->get()
            ->map(fn (PaymentReceiptModel $model): PaymentReceipt => $this->toDomain($model))
            ->all();
    }

    public function effectiveSum(string $paymentId, string $currency): Money
    {
        $sum = $this->effective($paymentId)
            ->where('payment_receipts.amount_currency', $currency)
            ->sum('payment_receipts.amount_minor');

        return Money::fromMinorUnits((int) $sum, $currency);
    }

    public function countRows(string $paymentId): int
    {
        return PaymentReceiptModel::where('payment_id', $paymentId)->count();
    }

    /** A receipt is effective unless a later row names it in supersedes_receipt_id. */
    private function effective(string $paymentId): \Illuminate\Database\Eloquent\Builder
    {
        return PaymentReceiptModel::query()
            ->where('payment_receipts.payment_id', $paymentId)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_receipts as later')
                    ->whereColumn('later.supersedes_receipt_id', 'payment_receipts.id');
            });
    }

    private function toDomain(PaymentReceiptModel $model): PaymentReceipt
    {
        return PaymentReceipt::reconstituteFromStorage(
            id: (string) $model->id,
            paymentId: (string) $model->payment_id,
            amount: Money::fromMinorUnits((int) $model->amount_minor, $model->amount_currency),
            receivedOn: substr((string) $model->received_on, 0, 10),
            bankReference: $model->bank_reference,
            supersedesReceiptId: $model->supersedes_receipt_id === null ? null : (string) $model->supersedes_receipt_id,
            recordedBy: $model->recorded_by,
            recordedAt: $model->recorded_at,
        );
    }
}
