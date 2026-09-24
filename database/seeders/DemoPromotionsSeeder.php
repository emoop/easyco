<?php

namespace Database\Seeders;

use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Four demo promotion codes for the manual end-to-end test of
 * cart -> checkout -> order (admin-panel-design.md §14's own
 * prerequisite). NOT registered in DatabaseSeeder — run explicitly:
 *
 *   php artisan db:seed --class=DemoPromotionsSeeder
 *
 * REFUSES IN PRODUCTION — a plain, friendly early return (not a thrown
 * exception: this is a manual, dev-only command, never part of an
 * automated deploy/CI step, so a clean "refused" message is better CLI
 * UX than a stack trace for the same outcome).
 *
 * IDEMPOTENT VIA findByCode() — D9's own instruction: every code is
 * looked up first; an existing one is reported and left completely
 * untouched (no update-in-place — Promotion has no such write path
 * for an existing code's config, and none is needed here).
 *
 * EVERY WRITE GOES THROUGH THE REAL DOMAIN — Promotion::create() +
 * PromotionRepository::save(), PromotionScope + PromotionScopeRepository
 * ::attach(), exactly as PromotionScopeController::store() does — never
 * a raw DB insert.
 *
 * Currency: EasyCo\Pricing\DefaultCurrency::get() — the same store-wide
 * default every checkout/pricing write in this app already uses, never
 * a second, independently-guessed currency.
 *
 * DEMOFIX's fixed amount — 50.00 in the store's DefaultCurrency,
 * chosen deliberately larger than a typical single demo item (this
 * codebase's own existing checkout test fixtures commonly price items
 * 10.00-90.00 — see CheckoutOrchestratorTest/OrderAdminReaderTest) so
 * that applying this code against one ordinary cart genuinely exercises
 * PromotionDiscountCalculator's own capping-at-subtotal behavior, not
 * merely a plain subtraction that always leaves something over.
 *
 * DEMOSCOPE's brand — the real brand with the most non-archived
 * products right now, found via a plain query against catalog_products
 * (no BrandModel relation exists to do this through — confirmed
 * against its real source); a store with zero brand-having products
 * has nothing sensible to scope this code to, so it is skipped and
 * reported, never scoped to an arbitrary/empty brand.
 */
class DemoPromotionsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoPromotionsSeeder refuses to run in production — these are demo/test-only codes.');

            return;
        }

        $promotions = app(PromotionRepository::class);
        $scopes = app(PromotionScopeRepository::class);

        $this->seedPercentage($promotions, 'DEMO10', 1000);
        $this->seedFixedAmount($promotions);
        $this->seedUsageLimited($promotions, 'DEMOONCE', 1000, 1);
        $this->seedScoped($promotions, $scopes);
    }

    private function alreadyPresent(PromotionRepository $promotions, string $code): bool
    {
        if ($promotions->findByCode($code) === null) {
            return false;
        }

        $this->command?->info("{$code}: already present, left untouched.");

        return true;
    }

    private function seedPercentage(PromotionRepository $promotions, string $code, int $basisPoints): void
    {
        if ($this->alreadyPresent($promotions, $code)) {
            return;
        }

        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $basisPoints,
        );
        $promotions->save($promotion);

        $this->command?->info("{$code}: created ({$basisPoints} basis points).");
    }

    private function seedFixedAmount(PromotionRepository $promotions): void
    {
        $code = 'DEMOFIX';

        if ($this->alreadyPresent($promotions, $code)) {
            return;
        }

        $amount = Money::fromDecimal('50.00', DefaultCurrency::get());

        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::FIXED_AMOUNT,
            discountAmount: $amount,
        );
        $promotions->save($promotion);

        $this->command?->info("{$code}: created (fixed {$amount->decimalValue()} {$amount->currency()->code()}).");
    }

    private function seedUsageLimited(PromotionRepository $promotions, string $code, int $basisPoints, int $usageLimitTotal): void
    {
        if ($this->alreadyPresent($promotions, $code)) {
            return;
        }

        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $basisPoints,
            usageLimitTotal: $usageLimitTotal,
        );
        $promotions->save($promotion);

        $this->command?->info("{$code}: created ({$basisPoints} basis points, usage_limit_total={$usageLimitTotal}).");
    }

    private function seedScoped(PromotionRepository $promotions, PromotionScopeRepository $scopes): void
    {
        $code = 'DEMOSCOPE';

        if ($this->alreadyPresent($promotions, $code)) {
            return;
        }

        $topBrandRow = DB::table('catalog_products')
            ->whereNotNull('brand_id')
            ->where('status', '!=', ProductStatus::ARCHIVED->value)
            ->select('brand_id', DB::raw('count(*) as product_count'))
            ->groupBy('brand_id')
            ->orderByDesc('product_count')
            ->first();

        if ($topBrandRow === null) {
            $this->command?->warn("{$code}: skipped — no brand has any non-archived products to scope it to.");

            return;
        }

        $brandName = BrandModel::find($topBrandRow->brand_id)?->name ?? "brand #{$topBrandRow->brand_id}";

        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1500,
        );
        $promotions->save($promotion);

        $scopes->attach(new PromotionScope(
            id: null,
            promotionId: $promotion->id(),
            scopeType: PromotionScopeType::BRAND,
            scopeReferenceId: (string) $topBrandRow->brand_id,
            mode: PromotionScopeMode::INCLUDE,
        ));

        $this->command?->info(
            "{$code}: created (1500 basis points, scoped to brand \"{$brandName}\" [{$topBrandRow->brand_id}], "
            ."{$topBrandRow->product_count} non-archived products)."
        );
    }
}
