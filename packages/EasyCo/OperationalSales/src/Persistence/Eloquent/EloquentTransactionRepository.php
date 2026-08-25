<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;

/**
 * Maps the Transaction aggregate (and all of its SaleLines) onto
 * operational_sales_transactions / operational_sales_sale_lines.
 *
 * No unique-constraint collision handling exists here: neither table has
 * a business-meaning unique index beyond its primary key (confirmed
 * against 2026_08_25_000002_.../2026_08_25_000004_..., the migrations
 * that created them) — operational_sales_sale_lines only carries FK
 * indexes and the plain lookup indexes named in
 * operational-sales-domain-design.md §2. There is nothing for the
 * SQLSTATE-23000-based pattern (catalog-domain-design.md §7) to detect
 * here; inventing a check for a constraint that doesn't exist would be
 * dead code, not defensive code.
 *
 * Rehydrating a Transaction with its SaleLines goes through
 * Transaction::reconstituteFromStorage() / SaleLine::reconstituteFromStorage(),
 * mirroring EasyCo\Catalog\Persistence\Eloquent\EloquentProductRepository's
 * use of Product/Variation's own persistence-only factories.
 */
final class EloquentTransactionRepository implements TransactionRepository
{
    public function save(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $transactionModel = $transaction->id() !== null
                ? TransactionModel::findOrFail($transaction->id())
                : new TransactionModel();

            $transactionModel->channel = $transaction->channel()->value;
            $transactionModel->save();

            if ($transaction->id() === null) {
                $transaction->assignId((string) $transactionModel->id);
            }

            foreach ($transaction->saleLines() as $saleLine) {
                $this->saveSaleLine($transactionModel, $saleLine);
            }
        });
    }

    private function saveSaleLine(TransactionModel $transactionModel, SaleLine $saleLine): void
    {
        $model = $saleLine->id() !== null
            ? SaleLineModel::findOrFail($saleLine->id())
            : new SaleLineModel();

        $model->transaction_id = $transactionModel->id;
        $model->client_id = $saleLine->clientId();
        $model->priceable_id = $saleLine->priceableId();
        $model->type = $saleLine->type()->value;
        $model->status = $saleLine->status()->value;
        $model->quantity = $saleLine->quantity();
        $model->amount_minor = $saleLine->amount()->minorValue();
        $model->amount_currency = $saleLine->amount()->currency()->code();
        $model->profit_minor = $saleLine->profit()->minorValue();
        $model->profit_currency = $saleLine->profit()->currency()->code();
        $model->recorded_at = $saleLine->recordedAt();
        $model->effective_at = $saleLine->effectiveAt();
        $model->originating_sale_line_id = $saleLine->originatingSaleLineId();
        $model->originating_reservation_line_id = $saleLine->originatingReservationLineId();

        $model->save();

        if ($saleLine->id() === null) {
            $saleLine->assignId((string) $model->id);
        }
    }

    public function findByIdWithSaleLines(string $id): ?Transaction
    {
        $model = TransactionModel::with('saleLines')->find($id);

        if ($model === null) {
            return null;
        }

        $saleLines = $model->saleLines
            ->map(fn (SaleLineModel $saleLineModel) => $this->toDomainSaleLine($saleLineModel))
            ->all();

        return Transaction::reconstituteFromStorage(
            id: (string) $model->id,
            channel: Channel::from($model->channel),
            saleLines: $saleLines,
        );
    }

    private function toDomainSaleLine(SaleLineModel $model): SaleLine
    {
        return SaleLine::reconstituteFromStorage(
            id: (string) $model->id,
            transactionId: (string) $model->transaction_id,
            clientId: (string) $model->client_id,
            priceableId: $model->priceable_id,
            type: SaleLineType::from($model->type),
            status: SaleLineStatus::from($model->status),
            quantity: $model->quantity,
            amount: Money::fromMinorUnits($model->amount_minor, $model->amount_currency),
            profit: Money::fromMinorUnits($model->profit_minor, $model->profit_currency),
            recordedAt: $model->recorded_at->toDateTimeImmutable(),
            effectiveAt: $model->effective_at->toDateTimeImmutable(),
            originatingSaleLineId: $model->originating_sale_line_id !== null ? (string) $model->originating_sale_line_id : null,
            originatingReservationLineId: $model->originating_reservation_line_id !== null ? (string) $model->originating_reservation_line_id : null,
        );
    }
}
