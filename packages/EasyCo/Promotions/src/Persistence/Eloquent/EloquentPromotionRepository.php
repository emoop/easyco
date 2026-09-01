<?php

namespace EasyCo\Promotions\Persistence\Eloquent;

use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionStatus;
use EasyCo\Promotions\Exceptions\PromotionCodeAlreadyExistsException;
use EasyCo\Promotions\Promotion;
use Illuminate\Database\QueryException;

/**
 * Maps the Promotion entity onto `promotions`. Never touches
 * `promotion_scopes` directly — that's EloquentPromotionScopeRepository's
 * own concern (Variant B, same split Pricing's PriceList/PriceListScope
 * repositories use).
 */
final class EloquentPromotionRepository implements PromotionRepository
{
    public function save(Promotion $promotion): void
    {
        $model = $promotion->id() !== null
            ? PromotionModel::findOrFail($promotion->id())
            : new PromotionModel();

        $model->code = $promotion->code();
        $model->discount_type = $promotion->discountType()->value;
        $model->discount_percentage_basis_points = $promotion->percentageBasisPoints();
        $model->discount_amount_minor = $promotion->discountAmount()?->minorValue();
        $model->discount_amount_currency = $promotion->discountAmount()?->currency()->code();
        $model->individual_use_only = $promotion->individualUseOnly();
        $model->exclude_sale_items = $promotion->excludeSaleItems();
        $model->minimum_spend_amount_minor = $promotion->minimumSpend()?->minorValue();
        $model->minimum_spend_amount_currency = $promotion->minimumSpend()?->currency()->code();
        $model->maximum_spend_amount_minor = $promotion->maximumSpend()?->minorValue();
        $model->maximum_spend_amount_currency = $promotion->maximumSpend()?->currency()->code();
        $model->new_customers_only = $promotion->newCustomersOnly();
        $model->usage_limit_total = $promotion->usageLimitTotal();
        $model->usage_limit_per_customer = $promotion->usageLimitPerCustomer();
        $model->usage_limit_items = $promotion->usageLimitItems();
        $model->valid_from = $promotion->validFrom();
        $model->valid_until = $promotion->validUntil();
        $model->status = $promotion->status()->value;

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isCodeUniqueViolation($e)) {
                throw PromotionCodeAlreadyExistsException::forCode($promotion->code());
            }

            throw $e;
        }

        if ($promotion->id() === null) {
            $promotion->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Promotion
    {
        $model = PromotionModel::find($id);

        return $model !== null ? $this->toDomainPromotion($model) : null;
    }

    public function findByCode(string $code): ?Promotion
    {
        $model = PromotionModel::where('code', strtolower(trim($code)))->first();

        return $model !== null ? $this->toDomainPromotion($model) : null;
    }

    /**
     * Detects a violation of promo_promotions_code_unique — SQLSTATE
     * 23000 + driver error code (MySQL 1062 / SQLite 19) is the primary
     * check, then errorInfo[2] narrows to this specific constraint —
     * never $e->getMessage() string matching (CLAUDE.md rule 3, mirrors
     * EloquentAccountRepository::isEmailUniqueViolation()).
     */
    private function isCodeUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1062, 19], true)) {
            return false;
        }

        $driverErrorMessage = (string) ($errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'promo_promotions_code_unique')
            || str_contains($driverErrorMessage, 'promotions.code');
    }

    private function toDomainPromotion(PromotionModel $model): Promotion
    {
        return Promotion::reconstituteFromStorage(
            id: (string) $model->id,
            code: $model->code,
            discountType: PromotionDiscountType::from($model->discount_type),
            percentageBasisPoints: $model->discount_percentage_basis_points,
            discountAmount: $this->toMoney($model->discount_amount_minor, $model->discount_amount_currency),
            individualUseOnly: $model->individual_use_only,
            excludeSaleItems: $model->exclude_sale_items,
            minimumSpend: $this->toMoney($model->minimum_spend_amount_minor, $model->minimum_spend_amount_currency),
            maximumSpend: $this->toMoney($model->maximum_spend_amount_minor, $model->maximum_spend_amount_currency),
            newCustomersOnly: $model->new_customers_only,
            usageLimitTotal: $model->usage_limit_total,
            usageLimitPerCustomer: $model->usage_limit_per_customer,
            usageLimitItems: $model->usage_limit_items,
            validFrom: $model->valid_from?->toDateTimeImmutable(),
            validUntil: $model->valid_until?->toDateTimeImmutable(),
            status: PromotionStatus::from($model->status),
        );
    }

    private function toMoney(?int $minorValue, ?string $currency): ?Money
    {
        if ($minorValue === null || $currency === null) {
            return null;
        }

        return Money::fromMinorUnits($minorValue, $currency);
    }
}
