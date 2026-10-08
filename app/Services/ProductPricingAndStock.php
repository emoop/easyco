<?php

namespace App\Services;

use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\ProductCost;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 2 (Price + Stock for SIMPLE products) + Step 2b (VARIABLE
 * products' own PRODUCT/VARIATION price mechanism) — the single place
 * ProductResource/CreateProduct/EditProduct/EditVariableProduct read
 * and write Regular Price, Sale Price, Cost, and Stock quantity. The
 * four original methods are keyed by a Variation's own priceableId()
 * (SIMPLE's universal Variation, or a VARIABLE product's own per-row
 * override); the four *ForProduct() methods are keyed by a Product's
 * own id directly (pricing-persistence-domain-design.md §4.3's
 * PRODUCT-level item — covers every current and future Variation of
 * that product unless a VARIATION-level override exists; the item-
 * level fallback itself is resolved by the real PriceResolver at read
 * time, not by this class, which only ever reads/writes exactly the
 * target it's given — see regularPriceDisplayForProduct()'s own
 * docblock). App/ layer, mirroring DetachProductFromCatalogLookup's
 * own "cross-package composition lives in app/" precedent — this
 * composes three separate EasyCo\* packages (Pricing twice over,
 * Inventory once), none of which may depend on each other or on
 * Catalog directly (CLAUDE.md rule 9).
 *
 * ProductCostRepository/PriceListItemRepository/PriceList are
 * documented INTERNAL to EasyCo\Pricing (pricing-domain-design.md
 * §4.2) — used directly here anyway, exactly as this task's own
 * instruction confirms: "mirroring how every other EasyCo\* repository
 * is already used directly from Filament resources in this codebase."
 *
 * REGULAR/SALE PRICE WRITE VIA THE TWO RESERVED SYSTEM PriceLists
 * ("Regular Prices"/"Manual Sale" — pricing-persistence-domain-
 * design.md §4.5), as either a VARIATION-level or PRODUCT-level
 * PriceListItem depending on which set of methods is called — the
 * caller decides the target, this class has no mode-switch of its
 * own. A missing system list is a genuine setup error on WRITE
 * (fail loud, RuntimeException — §4.6's own documented failure mode
 * for "pricing isn't configured at all") but never on READ (a missing
 * list simply has nothing to display yet).
 *
 * TAX TREATMENT: Price::inclusiveOfTax() — Bulgarian retail convention,
 * merchant-entered prices are VAT-inclusive — at a single flat rate
 * from config('services.pricing.default_tax_rate_basis_points', 0),
 * NOT a tax-rate-selection UI (separately deferred v2 "данъчна група"
 * work). Because the stored Money IS the price's tax-inclusive basis
 * (Price's own docblock: "whichever of net/gross is the price's stored
 * basis is exact by definition"), reading it back via ->gross() incurs
 * no rounding at all — the exact minor-unit amount originally entered.
 *
 * CLEARING A PRICE FIELD removes the underlying PriceListItem entirely
 * (§4.5: "active only while populated") — applied symmetrically to
 * BOTH regular and sale price, not only sale, since nothing in this
 * task's design makes an emptied regular price a different case: a
 * PriceListItem is current configuration, not a historical fact
 * (PriceListItem's own class docblock), so "no configuration" is
 * exactly as legitimate a state as "no sale price."
 *
 * COST HAS NO REMOVAL MECHANISM — ProductCostRepository's real
 * contract exposes only save()/findByPriceableIdAndCurrency(), no
 * delete()/remove(). Confirmed deliberate, not an oversight: this
 * mirrors ProductCost's own class docblock ("current configuration,"
 * same mutability posture as PriceListItem) — but unlike price, this
 * task was not asked to add a removal path, and the domain package
 * genuinely has none. writeCost() with a blank value is therefore a
 * real, reported no-op: it neither creates nor removes a row. A
 * previously-set cost stays exactly as it was until overwritten with a
 * different value.
 *
 * INPUT HARDENING (third pass, 3a): every amount that reaches a money
 * write from OUTSIDE this class is parsed by moneyFromTypedAmount(),
 * which routes it through MoneyInput instead of calling
 * Money::fromDecimal() on the submitted text directly — see that
 * method's own docblock for the saturation it prevents and why
 * MoneyInput's own ceiling is the right answer rather than a wider
 * (int) cast.
 */
final class ProductPricingAndStock
{
    private const REGULAR_PRICES_LIST = 'Regular Prices';

    private const MANUAL_SALE_LIST = 'Manual Sale';

    public function __construct(
        private readonly PriceListRepository $priceLists,
        private readonly PriceListItemRepository $priceListItems,
        private readonly ProductCostRepository $productCosts,
        private readonly StockLevelRepository $stockLevels,
    ) {
    }

    /**
     * Canonicalizes a raw, possibly differently-formatted decimal string
     * (e.g. "80" vs "80.00") to the exact same fixed-decimal-places
     * format Money::decimalValue() always produces — a REAL, CONFIRMED
     * GAP found while testing EditProduct's own diff-guard: Filament's
     * TextInput::numeric() registers a NumberStateCast on the field
     * (confirmed against the installed v5.8.1 source,
     * TextInput::getDefaultStateCasts()), which reformats a submitted
     * "80.00" down to "80" before it ever reaches $data — comparing
     * that raw submitted string directly against regularPriceDisplay()'s
     * own fixed "80.00" would treat an UNCHANGED price as changed.
     * Round-tripping the submitted value through Money first (same
     * currency, same decimalValue() call regularPriceDisplay() itself
     * uses) is the real fix, not a workaround around Filament's cast.
     *
     * The parse itself goes through moneyFromTypedAmount() (i.e.
     * MoneyInput), never Money::fromDecimal() directly: this method's
     * own argument is a submitted FORM FIELD, and a NumberStateCast
     * hands over a float-cast string for anything too wide for it
     * ("1.0E+19") — which Money::fromDecimal() cannot read at all.
     * Anything that is not an amount this app accepts is REFUSED here
     * (InvalidArgumentException, moneyFromTypedAmount()'s own
     * docblock), so a value that could never be stored cannot reach the
     * diff-guard as a normalized "80.00"-like string either.
     */
    public function normalizeDecimalDisplay(?string $decimal): ?string
    {
        if (! filled($decimal)) {
            return null;
        }

        return $this->moneyFromTypedAmount($decimal)->decimalValue();
    }

    public function regularPriceDisplay(string $priceableId): ?string
    {
        return $this->priceDisplayInSystemList(self::REGULAR_PRICES_LIST, $priceableId, PriceListItemTargetType::VARIATION);
    }

    public function salePriceDisplay(string $priceableId): ?string
    {
        return $this->priceDisplayInSystemList(self::MANUAL_SALE_LIST, $priceableId, PriceListItemTargetType::VARIATION);
    }

    /**
     * PRODUCT-level counterpart of regularPriceDisplay() — a VARIABLE
     * product's own single "Product Regular Price" field
     * (EditVariableProduct's own admin-panel-design.md §13.1 follow-on),
     * covering every current and future Variation of that product
     * automatically unless a VARIATION-level override exists
     * (pricing-persistence-domain-design.md §4.3's own item-level
     * fallback — resolved by the real PriceResolver at read time, not
     * by this display-only method, which only ever reads exactly the
     * target it's given).
     */
    public function regularPriceDisplayForProduct(string $productId): ?string
    {
        return $this->priceDisplayInSystemList(self::REGULAR_PRICES_LIST, $productId, PriceListItemTargetType::PRODUCT);
    }

    /** PRODUCT-level counterpart of salePriceDisplay() — see regularPriceDisplayForProduct()'s own docblock. */
    public function salePriceDisplayForProduct(string $productId): ?string
    {
        return $this->priceDisplayInSystemList(self::MANUAL_SALE_LIST, $productId, PriceListItemTargetType::PRODUCT);
    }

    public function costDisplay(string $priceableId): ?string
    {
        $currency = DefaultCurrency::get();
        $cost = $this->productCosts->findByPriceableIdAndCurrency($priceableId, $currency->code());

        return $cost?->cost()->decimalValue();
    }

    /**
     * A blank $priceableId (ProductResource::universalVariationId()
     * degrades to '' for a VARIABLE product with zero variations —
     * newly reachable once the VARIABLE creation wizard exists) is a
     * real, legitimate "nothing to report" case, mirroring
     * regularPriceDisplay()/salePriceDisplay()/costDisplay()'s own
     * identical read-side posture — never reached the repository at
     * all before those callers existed, since a SIMPLE product's
     * universal Variation always has a real, non-blank id by
     * construction. Short-circuited here rather than in
     * StockLevelRepository/StockLevel: StockLevel::forVariation('', 0)
     * genuinely, deliberately rejects a blank variationId (a real
     * domain invariant, confirmed against StockLevel's own
     * constructor) — that invariant is correct and untouched; this is
     * the one caller that must never reach it with a blank id.
     */
    public function stockQuantity(string $priceableId): int
    {
        if ($priceableId === '') {
            return 0;
        }

        return $this->stockLevels->findByVariationId($priceableId)->quantity();
    }

    /** @throws RuntimeException If the "Regular Prices" system list is missing. */
    public function writeRegularPrice(string $priceableId, ?string $decimal): void
    {
        $this->writePriceInSystemList(self::REGULAR_PRICES_LIST, $priceableId, $decimal, PriceListItemTargetType::VARIATION);
    }

    /** @throws RuntimeException If the "Manual Sale" system list is missing. */
    public function writeSalePrice(string $priceableId, ?string $decimal): void
    {
        $this->writePriceInSystemList(self::MANUAL_SALE_LIST, $priceableId, $decimal, PriceListItemTargetType::VARIATION);
    }

    /** PRODUCT-level counterpart of writeRegularPrice() — see regularPriceDisplayForProduct()'s own docblock. @throws RuntimeException If the "Regular Prices" system list is missing. */
    public function writeRegularPriceForProduct(string $productId, ?string $decimal): void
    {
        $this->writePriceInSystemList(self::REGULAR_PRICES_LIST, $productId, $decimal, PriceListItemTargetType::PRODUCT);
    }

    /** PRODUCT-level counterpart of writeSalePrice() — see regularPriceDisplayForProduct()'s own docblock. @throws RuntimeException If the "Manual Sale" system list is missing. */
    public function writeSalePriceForProduct(string $productId, ?string $decimal): void
    {
        $this->writePriceInSystemList(self::MANUAL_SALE_LIST, $productId, $decimal, PriceListItemTargetType::PRODUCT);
    }

    /** See this class's own docblock — a blank $decimal is a genuine no-op, not a removal; ProductCostRepository has no delete path. */
    public function writeCost(string $priceableId, ?string $decimal): void
    {
        if (! filled($decimal)) {
            return;
        }

        $currency = DefaultCurrency::get();
        $money = $this->moneyFromTypedAmount($decimal);
        $existing = $this->productCosts->findByPriceableIdAndCurrency($priceableId, $currency->code());

        if ($existing !== null) {
            $existing->updateCost($money);
            $this->productCosts->save($existing);

            return;
        }

        $this->productCosts->save(new ProductCost(id: null, priceableId: $priceableId, cost: $money));
    }

    public function writeStockQuantity(string $priceableId, int $quantity): void
    {
        $stockLevel = $this->stockLevels->findByVariationId($priceableId);
        $stockLevel->setQuantity($quantity);
        $this->stockLevels->save($stockLevel);
    }

    private function priceDisplayInSystemList(string $listName, string $targetId, PriceListItemTargetType $targetType): ?string
    {
        $list = $this->priceLists->findSystemListByName($listName);

        if ($list === null || $list->id() === null) {
            return null;
        }

        $item = $this->priceListItems->findByPriceListIdAndTarget($list->id(), $targetType, $targetId);

        return $item?->price()->gross()->decimalValue();
    }

    /**
     * requireSystemList() (fail-loud) is only ever reached on the
     * "actually persisting a real price value" path below — a REAL BUG
     * caught by running the full pre-existing suite, not anticipated in
     * advance: calling it unconditionally, before checking $decimal at
     * all, made every pre-existing product-creation test throw
     * RuntimeException the moment PRICE_MANAGE was held, purely because
     * regular_price was never submitted (blank is the overwhelmingly
     * common case — nobody sets a price on most of this file's fixture
     * products). A blank $decimal with no system list yet is a
     * genuinely unremarkable "never priced" state, not a setup error —
     * only findSystemListByName() (nullable, no throw) is used on that
     * path, exactly like priceDisplayInSystemList()'s own read-side
     * posture.
     */
    private function writePriceInSystemList(string $listName, string $targetId, ?string $decimal, PriceListItemTargetType $targetType): void
    {
        if (! filled($decimal)) {
            $list = $this->priceLists->findSystemListByName($listName);

            if ($list === null || $list->id() === null) {
                return;
            }

            $existing = $this->priceListItems->findByPriceListIdAndTarget($list->id(), $targetType, $targetId);

            if ($existing !== null) {
                $this->priceListItems->remove($existing->id());
            }

            return;
        }

        $list = $this->requireSystemList($listName);
        $existing = $this->priceListItems->findByPriceListIdAndTarget($list->id(), $targetType, $targetId);

        $money = $this->moneyFromTypedAmount($decimal);
        $taxRateBasisPoints = (int) config('services.pricing.default_tax_rate_basis_points', 0);
        $price = Price::inclusiveOfTax($money, $taxRateBasisPoints);

        if ($existing !== null) {
            $existing->updatePrice($price);
            $this->priceListItems->save($existing);

            return;
        }

        $this->priceListItems->save(new PriceListItem(
            id: null,
            priceListId: $list->id(),
            targetType: $targetType,
            targetId: $targetId,
            price: $price,
        ));
    }

    /**
     * An amount box's submitted text as Money — through MoneyInput, never
     * Money::fromDecimal() straight on the submitted value (input
     * hardening pass 3, item 3a).
     *
     * WHY THIS METHOD EXISTS, I.E. WHAT IT PREVENTS: Money::
     * decimalStringToMinorUnits() ends in `(int) $digits`, so a digit
     * string long enough to overflow converts SILENTLY to PHP_INT_MAX —
     * Money::fromDecimal('9999999999999999999.00', ...) is exactly
     * 9223372036854775807 minor units, a price nobody typed, stored as
     * fact. Casting via a float first does not help: PHP's default
     * precision=14 discards the low digits ("123456789012345.67" becomes
     * 1.2345678901235E+14) — for some widths an unreadable exponent that
     * Money refuses, for others a rounded amount that Money accepts as if
     * it were entered.
     *
     * MoneyInput owns the app-wide answer instead: at most
     * MoneyInput::MAX_INTEGER_DIGITS (9) integer digits, the same ceiling
     * ShippingMethodWriter and PaymentReceiptRecorder already apply to
     * their own amounts, with everything else about the text (separators,
     * spaces, currency decimals) treated the same way the rest of the
     * admin panel treats it. So a value this app could never have meant is
     * refused HERE, deterministically, in every format it can arrive in —
     * and a refused value is never written, in whole or in saturated part.
     *
     * @throws InvalidArgumentException A value that is not an amount, or is wider than
     *   MoneyInput::MAX_INTEGER_DIGITS integer digits. The same exception class
     *   Money::fromDecimal() already threw for text it could not read, so no caller's
     *   failure mode changes — what changes is that PHP_INT_MAX saturation can no
     *   longer reach storage at all.
     */
    private function moneyFromTypedAmount(string $decimal): Money
    {
        $money = MoneyInput::parse($decimal, DefaultCurrency::get());

        if ($money === null) {
            throw new InvalidArgumentException(
                'Refused an amount that is not a plain decimal of at most '.MoneyInput::MAX_INTEGER_DIGITS.
                ' integer digits: "'.mb_substr($decimal, 0, 40).'".'
            );
        }

        return $money;
    }

    private function requireSystemList(string $name): PriceList
    {
        $list = $this->priceLists->findSystemListByName($name);

        if ($list === null) {
            throw new RuntimeException(
                "The reserved system PriceList \"{$name}\" does not exist — pricing is not set up correctly. ".
                'Run EasyCo\Pricing\Seeders\PricingSystemListsSeeder once per store (pricing-persistence-domain-design.md §4.5/§4.6).'
            );
        }

        return $list;
    }
}
