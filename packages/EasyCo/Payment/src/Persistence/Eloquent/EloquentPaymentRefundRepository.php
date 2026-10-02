<?php

namespace EasyCo\Payment\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Payment\RefundLine;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;

class EloquentPaymentRefundRepository implements PaymentRefundRepository
{
    public function save(PaymentRefund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            $isNew = $refund->id() === null;
            $model = $isNew ? new PaymentRefundModel() : PaymentRefundModel::findOrFail($refund->id());
            $breakdown = $refund->breakdown();

            $model->payment_id = $refund->paymentId();
            $model->order_id = $refund->orderId();
            $model->amount_minor = $refund->amount()->minorValue();
            $model->amount_currency = $refund->amount()->currency()->code();
            $model->channel = $refund->channel()->value;
            $model->goods_minor = $breakdown->goods->minorValue();
            $model->shipping_minor = $breakdown->shipping->minorValue();
            $model->adjustment_minor = $breakdown->adjustment->minorValue();
            $model->deduction_minor = $breakdown->deduction->minorValue();
            $model->deduction_reason = $breakdown->deductionReason;
            $model->reason = $refund->reason();
            $model->refunded_by = $refund->refundedBy();
            $model->status = $refund->status()->value;
            $model->failure_reason = $refund->failureReason();
            $model->paid_out_at = $refund->paidOutAt();
            $model->paid_out_reference = $refund->paidOutReference();
            $model->paid_out_note = $refund->paidOutNote();
            $model->paid_out_by = $refund->paidOutBy();

            $model->save();

            if ($isNew) {
                $refund->assignId((string) $model->id);

                // The per-line rows are written once, with the refund: they are
                // its record, never edited afterwards (a correction is a new refund).
                foreach ($breakdown->lines as $line) {
                    PaymentRefundLineModel::create([
                        'payment_refund_id' => $model->id,
                        'sale_line_id' => $line->saleLineId,
                        'amount_minor' => $line->amount->minorValue(),
                        'amount_currency' => $line->amount->currency()->code(),
                    ]);
                }
            }
        });
    }

    public function findById(string $id): ?PaymentRefund
    {
        $model = PaymentRefundModel::find($id);

        return $model !== null ? $this->toDomainPaymentRefund($model, $this->linesFor([$model->id])) : null;
    }

    /** @return PaymentRefund[] */
    public function findByPaymentId(string $paymentId): array
    {
        $models = PaymentRefundModel::where('payment_id', $paymentId)->get();
        $lines = $this->linesFor($models->pluck('id')->all());

        return $models->map(fn (PaymentRefundModel $model) => $this->toDomainPaymentRefund($model, $lines))->all();
    }

    /**
     * One SUM over the payment's own rows — see the contract's docblock for
     * why this is a query rather than a get() and an add-up. `sum()` returns
     * 0 (not null) when nothing matches, which is exactly the "nothing
     * refunded yet" answer R8(a)'s cap needs; the cast is for the driver
     * returning the total as a string.
     */
    public function sumCountingForPayment(string $paymentId, string $currency): Money
    {
        $sum = PaymentRefundModel::where('payment_id', $paymentId)
            ->whereIn('status', $this->countingValues())
            ->sum('amount_minor');

        return Money::fromMinorUnits((int) $sum, $currency);
    }

    /** @return list<string> the stored values of the counting states */
    private function countingValues(): array
    {
        return array_map(static fn (PaymentRefundStatus $status): string => $status->value, PaymentRefundStatus::counting());
    }

    public function sumCountingLineAmounts(array $saleLineIds): array
    {
        if ($saleLineIds === []) {
            return [];
        }

        $rows = DB::table('payment_refund_lines as l')
            ->join('payment_refunds as r', 'r.id', '=', 'l.payment_refund_id')
            ->whereIn('l.sale_line_id', $saleLineIds)
            ->whereIn('r.status', $this->countingValues())
            ->groupBy('l.sale_line_id')
            ->selectRaw('l.sale_line_id as sale_line_id, SUM(l.amount_minor) as total')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            $sums[(string) $row->sale_line_id] = (int) $row->total;
        }

        return $sums;
    }

    public function sumCountingShippingForOrder(string $orderId): int
    {
        return (int) PaymentRefundModel::where('order_id', $orderId)
            ->whereIn('status', $this->countingValues())
            ->sum('shipping_minor');
    }

    /**
     * @param  array<int, int|string>  $refundIds
     * @return array<int, list<RefundLine>> keyed by refund id
     */
    private function linesFor(array $refundIds): array
    {
        if ($refundIds === []) {
            return [];
        }

        $grouped = [];

        foreach (PaymentRefundLineModel::whereIn('payment_refund_id', $refundIds)->orderBy('id')->get() as $row) {
            $grouped[(int) $row->payment_refund_id][] = new RefundLine(
                (string) $row->sale_line_id,
                Money::fromMinorUnits((int) $row->amount_minor, $row->amount_currency),
            );
        }

        return $grouped;
    }

    /** @param array<int, list<RefundLine>> $lines */
    private function toDomainPaymentRefund(PaymentRefundModel $model, array $lines): PaymentRefund
    {
        $currency = $model->amount_currency;
        $money = static fn (mixed $minor): Money => Money::fromMinorUnits((int) $minor, $currency);

        return PaymentRefund::reconstituteFromStorage(
            id: (string) $model->id,
            paymentId: $model->payment_id,
            orderId: $model->order_id,
            amount: $money($model->amount_minor),
            channel: RefundChannel::from($model->channel),
            breakdown: new RefundBreakdown(
                goods: $money($model->goods_minor),
                shipping: $money($model->shipping_minor),
                adjustment: $money($model->adjustment_minor),
                deduction: $money($model->deduction_minor),
                deductionReason: $model->deduction_reason,
                lines: $lines[(int) $model->id] ?? [],
            ),
            reason: $model->reason,
            refundedBy: $model->refunded_by,
            status: PaymentRefundStatus::from($model->status),
            failureReason: $model->failure_reason,
            paidOutAt: $model->paid_out_at !== null ? DateTimeImmutable::createFromInterface($model->paid_out_at) : null,
            paidOutReference: $model->paid_out_reference,
            paidOutNote: $model->paid_out_note,
            paidOutBy: $model->paid_out_by,
        );
    }
}
