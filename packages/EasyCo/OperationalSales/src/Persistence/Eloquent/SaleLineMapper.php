<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Pricing\Money;

/**
 * The one place SaleLineModel <-> SaleLine mapping happens, in both
 * directions — operational-sales-domain-design.md §3.13 D5. Previously
 * duplicated, byte-for-byte, as private toDomainSaleLine() methods on
 * BOTH EloquentTransactionRepository and EloquentInstallmentPlanRepository
 * — consolidated here; behaviour-preserving, not a redesign.
 *
 * `transaction_id` IS DELIBERATELY NOT PART OF fill() — the one field
 * this mapper does not own. EloquentTransactionRepository::saveSaleLine()
 * still sets it itself, directly from the just-saved TransactionModel's
 * own id, not from $saleLine->transactionId() — a fresh SaleLine's own
 * transactionId may still be the empty-string placeholder (§3.9) at the
 * point saveSaleLine() runs, exactly the timing assignTransactionId()
 * exists to resolve; this mapper has no involvement in that resolution
 * and must not paper over it by reading a possibly-stale value.
 * `installment_plan_id` is likewise never written here —
 * EloquentInstallmentPlanRepository links an already-persisted SaleLine
 * to a plan via its own linkSaleLinesToPlan(), never through this class.
 */
final class SaleLineMapper
{
    public static function toDomain(SaleLineModel $model): SaleLine
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
            productName: $model->product_name,
            sku: $model->sku,
            regularUnitPrice: self::moneyOrNull($model->regular_unit_price_minor, $model->regular_unit_price_currency, 'regularUnitPrice'),
            finalUnitPrice: self::moneyOrNull($model->final_unit_price_minor, $model->final_unit_price_currency, 'finalUnitPrice'),
            promotionDiscountShare: self::moneyOrNull($model->promotion_discount_share_minor, $model->promotion_discount_share_currency, 'promotionDiscountShare'),
            discretionaryDiscount: self::moneyOrNull($model->discretionary_discount_minor, $model->discretionary_discount_currency, 'discretionaryDiscount'),
            netPaidAmount: self::moneyOrNull($model->net_paid_amount_minor, $model->net_paid_amount_currency, 'netPaidAmount'),
            soldAttributes: $model->sold_attributes,
            unitCost: self::moneyOrNull($model->unit_cost_minor, $model->unit_cost_currency, 'unitCost'),
        );
    }

    /**
     * Assigns every SaleLine field onto $model's own attributes — does
     * NOT call save() and does NOT set transaction_id/installment_plan_id
     * (see this class's own docblock for why). The caller
     * (EloquentTransactionRepository::saveSaleLine()) still owns the
     * actual write/transaction boundary.
     */
    public static function fill(SaleLineModel $model, SaleLine $saleLine): void
    {
        $model->client_id = $saleLine->clientId();
        $model->priceable_id = $saleLine->priceableId();
        $model->product_name = $saleLine->productName();
        $model->sku = $saleLine->sku();
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

        self::fillMoneyOrNull($model, 'regular_unit_price', $saleLine->regularUnitPrice());
        self::fillMoneyOrNull($model, 'final_unit_price', $saleLine->finalUnitPrice());
        self::fillMoneyOrNull($model, 'promotion_discount_share', $saleLine->promotionDiscountShare());
        self::fillMoneyOrNull($model, 'discretionary_discount', $saleLine->discretionaryDiscount());
        self::fillMoneyOrNull($model, 'net_paid_amount', $saleLine->netPaidAmount());
        self::fillMoneyOrNull($model, 'unit_cost', $saleLine->unitCost());

        $model->sold_attributes = $saleLine->soldAttributes();
    }

    /**
     * NULL only when BOTH halves of the pair are NULL (a legacy row,
     * §3.13 E-D5). A half-populated pair — one half set, the other NULL
     * — is not a legacy row's shape at all (a pre-migration row has
     * NEITHER column; §3.13's own migration adds both columns of every
     * pair together, in the same migration) — it is a corrupt row, and
     * silently treating it as "unset" would hide that corruption instead
     * of surfacing it.
     */
    private static function moneyOrNull(?int $minorValue, ?string $currency, string $fieldName): ?Money
    {
        if ($minorValue === null && $currency === null) {
            return null;
        }

        if ($minorValue === null || $currency === null) {
            throw new \RuntimeException(
                "SaleLineMapper: {$fieldName} is a corrupt row — its minor/currency pair is half-populated ".
                '(one is NULL, the other is not), which no legitimate write path produces.'
            );
        }

        return Money::fromMinorUnits($minorValue, $currency);
    }

    private static function fillMoneyOrNull(SaleLineModel $model, string $columnPrefix, ?Money $money): void
    {
        $model->{"{$columnPrefix}_minor"} = $money?->minorValue();
        $model->{"{$columnPrefix}_currency"} = $money?->currency()->code();
    }
}
