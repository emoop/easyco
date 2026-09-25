<?php

namespace EasyCo\OperationalSales\Tests;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Pricing\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaleLineTest extends TestCase
{
    private function money(int $minorUnits = 1000): Money
    {
        return Money::fromMinorUnits($minorUnits, 'EUR');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25 10:00:00');
    }

    /**
     * @return array{priceableId: ?string, type: SaleLineType, productName: ?string, sku: ?string}
     */
    private function baseArgsFor(SaleLineType $type): array
    {
        $priceableId = in_array($type, [SaleLineType::SHIPPING, SaleLineType::INSTALLMENT_PAYMENT], true)
            ? null
            : 'priceable-1';

        // Per §3.12: productName/sku required only for SALE.
        $productName = $type === SaleLineType::SALE ? 'Product One' : null;
        $sku = $type === SaleLineType::SALE ? 'SKU-1' : null;

        return ['priceableId' => $priceableId, 'type' => $type, 'productName' => $productName, 'sku' => $sku];
    }

    /**
     * Stage 4b (D8/D9) — SaleLine's constructor is now private, so every
     * test in this file's earlier (pre-§3.13-create()) section builds a
     * line through this dispatcher instead of `new SaleLine(...)`: SALE
     * goes through create() (which now requires the full §3.13 snapshot
     * and runs its own formula/Tier-B pre-checks before ever reaching the
     * constructor), every other type goes through createNonSale() (Tier
     * A only, no pre-checks of its own). Defaults here match this
     * section's own pre-existing baseline (quantity 1, amount/profit
     * money(1000)/money(200)) — NOT createArgs()'s own §3.13-test
     * baseline further down, which is a separate, already-correct
     * fixture left untouched.
     *
     * @param array<string, mixed> $overrides
     */
    private function construct(SaleLineType $type, array $overrides = []): SaleLine
    {
        $args = array_merge([
            'transactionId' => 'txn-1',
            'clientId' => 'client-1',
            'priceableId' => in_array($type, [SaleLineType::SHIPPING, SaleLineType::INSTALLMENT_PAYMENT], true) ? null : 'priceable-1',
            'status' => SaleLineStatus::PENDING,
            'quantity' => 1,
            'amount' => $this->money(),
            'profit' => $this->money(200),
            'recordedAt' => $this->now(),
            'effectiveAt' => $this->now(),
            'originatingSaleLineId' => null,
            'originatingReservationLineId' => null,
            'productName' => $type === SaleLineType::SALE ? 'Product One' : null,
            'sku' => $type === SaleLineType::SALE ? 'SKU-1' : null,
            'regularUnitPrice' => null,
            'finalUnitPrice' => null,
            'promotionDiscountShare' => null,
            'discretionaryDiscount' => null,
            'netPaidAmount' => null,
            'soldAttributes' => null,
            'unitCost' => null,
        ], $overrides);

        if ($type === SaleLineType::SALE) {
            return SaleLine::create(
                transactionId: $args['transactionId'],
                clientId: $args['clientId'],
                priceableId: $args['priceableId'],
                status: $args['status'],
                quantity: $args['quantity'],
                amount: $args['amount'],
                profit: $args['profit'],
                recordedAt: $args['recordedAt'],
                effectiveAt: $args['effectiveAt'],
                productName: $args['productName'],
                sku: $args['sku'],
                regularUnitPrice: $args['regularUnitPrice'] ?? $this->money(1000),
                finalUnitPrice: $args['finalUnitPrice'] ?? $this->money(1000),
                promotionDiscountShare: $args['promotionDiscountShare'] ?? $this->money(0),
                discretionaryDiscount: $args['discretionaryDiscount'] ?? $this->money(0),
                netPaidAmount: $args['netPaidAmount'] ?? $args['amount'],
                soldAttributes: $args['soldAttributes'] ?? [],
                unitCost: $args['unitCost'],
                originatingReservationLineId: $args['originatingReservationLineId'],
            );
        }

        return SaleLine::createNonSale(
            type: $type,
            transactionId: $args['transactionId'],
            clientId: $args['clientId'],
            priceableId: $args['priceableId'],
            status: $args['status'],
            quantity: $args['quantity'],
            amount: $args['amount'],
            profit: $args['profit'],
            recordedAt: $args['recordedAt'],
            effectiveAt: $args['effectiveAt'],
            originatingSaleLineId: $args['originatingSaleLineId'],
            originatingReservationLineId: $args['originatingReservationLineId'],
            productName: $args['productName'],
            sku: $args['sku'],
            regularUnitPrice: $args['regularUnitPrice'],
            finalUnitPrice: $args['finalUnitPrice'],
            promotionDiscountShare: $args['promotionDiscountShare'],
            discretionaryDiscount: $args['discretionaryDiscount'],
            netPaidAmount: $args['netPaidAmount'],
            soldAttributes: $args['soldAttributes'],
            unitCost: $args['unitCost'],
        );
    }

    public static function allTypesProvider(): array
    {
        return [
            'SALE' => [SaleLineType::SALE],
            'RESERVATION' => [SaleLineType::RESERVATION],
            'REFUND' => [SaleLineType::REFUND],
            'SHIPPING' => [SaleLineType::SHIPPING],
            'INSTALLMENT_PAYMENT' => [SaleLineType::INSTALLMENT_PAYMENT],
        ];
    }

    #[DataProvider('allTypesProvider')]
    public function test_valid_construction_succeeds_for_every_type(SaleLineType $type): void
    {
        $args = $this->baseArgsFor($type);

        $line = $this->construct($type, [
            'priceableId' => $args['priceableId'],
            'productName' => $args['productName'],
            'sku' => $args['sku'],
        ]);

        $this->assertSame($type, $line->type());
        $this->assertSame($args['priceableId'], $line->priceableId());
        $this->assertSame($args['productName'], $line->productName());
        $this->assertSame($args['sku'], $line->sku());
    }

    public static function priceableIdRequiredTypesProvider(): array
    {
        return [
            'SALE' => [SaleLineType::SALE],
            'RESERVATION' => [SaleLineType::RESERVATION],
            'REFUND' => [SaleLineType::REFUND],
        ];
    }

    /**
     * SALE's own case now uses an EMPTY STRING, not null: create()'s
     * priceableId parameter is non-nullable (string, not ?string) — the
     * type system itself now rejects null before Tier A's own runtime
     * check ever gets a chance to (a stronger guarantee than before, not
     * a weaker one). Tier A's "non-empty string" rule still has a real,
     * reachable failure mode for create() callers: an empty string.
     */
    #[DataProvider('priceableIdRequiredTypesProvider')]
    public function test_priceable_id_is_required_for_sale_reservation_and_refund(SaleLineType $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct($type, [
            'priceableId' => $type === SaleLineType::SALE ? '' : null,
        ]);
    }

    public static function priceableIdForbiddenTypesProvider(): array
    {
        return [
            'SHIPPING' => [SaleLineType::SHIPPING],
            'INSTALLMENT_PAYMENT' => [SaleLineType::INSTALLMENT_PAYMENT],
        ];
    }

    #[DataProvider('priceableIdForbiddenTypesProvider')]
    public function test_priceable_id_is_forbidden_for_shipping_and_installment_payment(SaleLineType $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct($type, ['priceableId' => 'priceable-1']);
    }

    /**
     * quantity<=0 is a Tier A rule, unconditional on type — tested here
     * via a non-SALE type (createNonSale() has no pre-checks of its own
     * beyond refusing SALE, so it reaches the constructor's own quantity
     * check directly). Testing this via SALE/create() would risk one of
     * create()'s own formula pre-checks (amount == finalUnitPrice x
     * quantity, netPaidAmount's formula) intercepting first for the
     * wrong reason — not a concern for a type-agnostic Tier A rule like
     * this one.
     */
    public function test_quantity_of_zero_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct(SaleLineType::RESERVATION, ['quantity' => 0]);
    }

    public function test_negative_quantity_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct(SaleLineType::RESERVATION, ['quantity' => -1]);
    }

    /**
     * SALE removed from this provider: create() has no
     * $originatingSaleLineId parameter at all (only REFUND ever legally
     * carries one) — there is no longer any way to even ATTEMPT setting
     * it on a fresh SALE line through the public API, a stronger
     * guarantee than the old runtime-only rejection. The Tier A rule
     * itself stays fully covered by the three remaining, still-reachable
     * cases below.
     */
    public static function nonRefundTypesProvider(): array
    {
        return [
            'RESERVATION' => [SaleLineType::RESERVATION],
            'SHIPPING' => [SaleLineType::SHIPPING],
            'INSTALLMENT_PAYMENT' => [SaleLineType::INSTALLMENT_PAYMENT],
        ];
    }

    #[DataProvider('nonRefundTypesProvider')]
    public function test_originating_sale_line_id_is_rejected_on_every_type_except_refund(SaleLineType $type): void
    {
        $args = $this->baseArgsFor($type);

        $this->expectException(\InvalidArgumentException::class);

        $this->construct($type, [
            'priceableId' => $args['priceableId'],
            'originatingSaleLineId' => 'sale-line-1',
        ]);
    }

    public function test_originating_sale_line_id_is_accepted_on_refund(): void
    {
        $line = $this->construct(SaleLineType::REFUND, [
            'status' => SaleLineStatus::COMPLETED,
            'originatingSaleLineId' => 'sale-line-1',
        ]);

        $this->assertSame('sale-line-1', $line->originatingSaleLineId());
    }

    public static function nonSaleTypesProvider(): array
    {
        return [
            'RESERVATION' => [SaleLineType::RESERVATION],
            'REFUND' => [SaleLineType::REFUND],
            'SHIPPING' => [SaleLineType::SHIPPING],
            'INSTALLMENT_PAYMENT' => [SaleLineType::INSTALLMENT_PAYMENT],
        ];
    }

    #[DataProvider('nonSaleTypesProvider')]
    public function test_originating_reservation_line_id_is_rejected_on_every_type_except_sale(SaleLineType $type): void
    {
        $args = $this->baseArgsFor($type);

        $this->expectException(\InvalidArgumentException::class);

        $this->construct($type, [
            'priceableId' => $args['priceableId'],
            'originatingReservationLineId' => 'reservation-line-1',
        ]);
    }

    public function test_originating_reservation_line_id_is_accepted_on_sale(): void
    {
        $line = $this->construct(SaleLineType::SALE, [
            'status' => SaleLineStatus::COMPLETED,
            'originatingReservationLineId' => 'reservation-line-1',
        ]);

        $this->assertSame('reservation-line-1', $line->originatingReservationLineId());
    }

    private function placeholderLine(): SaleLine
    {
        return $this->construct(SaleLineType::SALE, ['transactionId' => '']);
    }

    public function test_an_empty_string_transaction_id_placeholder_is_accepted_at_construction(): void
    {
        $line = $this->placeholderLine();

        $this->assertSame('', $line->transactionId());
    }

    public function test_assign_transaction_id_can_only_be_called_once(): void
    {
        $line = $this->placeholderLine();

        $line->assignTransactionId('txn-1');
        $this->assertSame('txn-1', $line->transactionId());

        $this->expectException(\LogicException::class);
        $line->assignTransactionId('txn-2');
    }

    public function test_assign_transaction_id_throws_when_transaction_id_is_already_real(): void
    {
        $line = $this->construct(SaleLineType::SALE);

        $this->expectException(\LogicException::class);
        $line->assignTransactionId('txn-2');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $line = $this->construct(SaleLineType::SALE);

        $line->assignId('line-1');
        $this->assertSame('line-1', $line->id());

        $this->expectException(\LogicException::class);
        $line->assignId('line-2');
    }

    public function test_reconstitute_from_storage_round_trips_correctly(): void
    {
        $recordedAt = $this->now();
        $effectiveAt = new DateTimeImmutable('2026-08-20 09:00:00');

        $line = SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: null,
            type: SaleLineType::INSTALLMENT_PAYMENT,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(500),
            profit: $this->money(0),
            recordedAt: $recordedAt,
            effectiveAt: $effectiveAt,
        );

        $this->assertSame('line-1', $line->id());
        $this->assertSame('txn-1', $line->transactionId());
        $this->assertSame('client-1', $line->clientId());
        $this->assertNull($line->priceableId());
        $this->assertSame(SaleLineType::INSTALLMENT_PAYMENT, $line->type());
        $this->assertSame(SaleLineStatus::COMPLETED, $line->status());
        $this->assertSame(1, $line->quantity());
        $this->assertTrue($line->amount()->equals($this->money(500)));
        $this->assertTrue($line->profit()->equals($this->money(0)));
        $this->assertSame($recordedAt, $line->recordedAt());
        $this->assertSame($effectiveAt, $line->effectiveAt());
    }

    /**
     * Confirms SaleLine's immutability rule (§3.2) holds structurally, not
     * just by convention: the only public instance methods are the
     * plain accessors listed below, assignId(), and assignTransactionId()
     * (a narrow, one-time structural-reference backfill — see the class
     * docblock for why that's not a violation of §3.2) — no setter or
     * other mutation method exists on this class. __construct() is no
     * longer in this list (stage 4b — it's private now, so
     * getMethods(IS_PUBLIC) never returns it); createNonSale() is new.
     * Written as a Reflection-based allow-list so that adding any new
     * public method to SaleLine in the future forces a conscious update
     * to this test, rather than silently slipping a mutator past the
     * class's central invariant.
     */
    public function test_no_mutation_method_exists_beyond_assign_id(): void
    {
        $expectedPublicMethods = [
            'reconstituteFromStorage',
            'create',
            'createNonSale',
            'id',
            'assignId',
            'assignTransactionId',
            'transactionId',
            'clientId',
            'priceableId',
            'type',
            'status',
            'quantity',
            'amount',
            'profit',
            'recordedAt',
            'effectiveAt',
            'originatingSaleLineId',
            'originatingReservationLineId',
            'productName',
            'sku',
            'regularUnitPrice',
            'finalUnitPrice',
            'promotionDiscountShare',
            'discretionaryDiscount',
            'netPaidAmount',
            'soldAttributes',
            'unitCost',
        ];

        $actualPublicMethods = array_map(
            static fn (\ReflectionMethod $method) => $method->getName(),
            (new \ReflectionClass(SaleLine::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($expectedPublicMethods);
        sort($actualPublicMethods);

        $this->assertSame($expectedPublicMethods, $actualPublicMethods);
    }

    /**
     * D8 (stage 4b) — the constructor itself must be private, so no
     * caller outside this class can ever construct a SaleLine except
     * through create()/createNonSale()/reconstituteFromStorage().
     */
    public function test_the_constructor_is_private(): void
    {
        $constructor = (new \ReflectionClass(SaleLine::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    /**
     * D9 (stage 4b) — the $reconstituting flag this class used to carry
     * (stage 2's temporary mechanism) no longer exists at all, now that
     * Tier B lives exclusively in create() and the constructor never
     * needs to suppress it.
     */
    public function test_the_reconstituting_flag_no_longer_exists(): void
    {
        $propertyNames = array_map(
            static fn (\ReflectionProperty $property) => $property->getName(),
            (new \ReflectionClass(SaleLine::class))->getProperties(),
        );

        $this->assertNotContains('reconstituting', $propertyNames);
    }

    /**
     * D9 — createNonSale() refuses SaleLineType::SALE outright; a caller
     * wanting a SALE line must use create() instead, which enforces the
     * full §3.13 invariant set createNonSale() deliberately does not.
     */
    public function test_create_non_sale_refuses_sale_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SaleLine::createNonSale(
            type: SaleLineType::SALE,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
        );
    }

    /**
     * operational-sales-domain-design.md §3.12: productName/sku required
     * only for SaleLineType::SALE.
     */
    public function test_sale_type_with_real_product_name_and_sku_succeeds(): void
    {
        $line = $this->construct(SaleLineType::SALE, [
            'status' => SaleLineStatus::COMPLETED,
        ]);

        $this->assertSame('Product One', $line->productName());
        $this->assertSame('SKU-1', $line->sku());
    }

    /**
     * Empty strings, not null: create()'s productName/sku parameters are
     * non-nullable (string, not ?string) — null is now rejected by the
     * type system itself before Tier B's own runtime check ever runs (a
     * stronger guarantee than before). Tier B's "non-empty" rule still
     * has a real, reachable failure mode for create() callers: an empty
     * string, which is what this now tests.
     */
    public static function emptyProductNameOrSkuProvider(): array
    {
        return [
            'empty productName' => ['', 'SKU-1'],
            'empty sku' => ['Product One', ''],
            'both empty' => ['', ''],
        ];
    }

    #[DataProvider('emptyProductNameOrSkuProvider')]
    public function test_sale_type_with_an_empty_product_name_or_sku_throws(string $productName, string $sku): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct(SaleLineType::SALE, [
            'status' => SaleLineStatus::COMPLETED,
            'productName' => $productName,
            'sku' => $sku,
        ]);
    }

    public static function nonNullProductNameOrSkuProvider(): array
    {
        return [
            'non-null productName' => ['Product One', null],
            'non-null sku' => [null, 'SKU-1'],
            'both non-null' => ['Product One', 'SKU-1'],
        ];
    }

    #[DataProvider('nonNullProductNameOrSkuProvider')]
    public function test_shipping_type_with_a_non_null_product_name_or_sku_throws(?string $productName, ?string $sku): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct(SaleLineType::SHIPPING, [
            'status' => SaleLineStatus::COMPLETED,
            'productName' => $productName,
            'sku' => $sku,
        ]);
    }

    public function test_shipping_type_with_both_null_succeeds(): void
    {
        $line = $this->construct(SaleLineType::SHIPPING, [
            'status' => SaleLineStatus::COMPLETED,
            'productName' => null,
            'sku' => null,
        ]);

        $this->assertNull($line->productName());
        $this->assertNull($line->sku());
    }

    public function test_reservation_type_is_unconstrained_on_product_name_and_sku(): void
    {
        $withoutSnapshot = $this->construct(SaleLineType::RESERVATION);

        $withSnapshot = $this->construct(SaleLineType::RESERVATION, [
            'productName' => 'Product One',
            'sku' => 'SKU-1',
        ]);

        $this->assertNull($withoutSnapshot->productName());
        $this->assertSame('Product One', $withSnapshot->productName());
    }

    public function test_refund_type_is_unconstrained_on_product_name_and_sku(): void
    {
        $withoutSnapshot = $this->construct(SaleLineType::REFUND, [
            'status' => SaleLineStatus::COMPLETED,
            'originatingSaleLineId' => 'sale-line-1',
        ]);

        $this->assertNull($withoutSnapshot->productName());
        $this->assertNull($withoutSnapshot->sku());
    }

    // --- §3.13: SaleLine::create() ---------------------------------------

    /** @return array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}> */
    private function soldAttributes(): array
    {
        return [
            ['definitionId' => '1', 'definitionCode' => 'color', 'definitionName' => 'Color', 'valueId' => '10', 'value' => 'Black'],
            ['definitionId' => '2', 'definitionCode' => 'size', 'definitionName' => 'Size', 'valueId' => '20', 'value' => 'M'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createArgs(array $overrides = []): array
    {
        return array_merge([
            'transactionId' => 'txn-1',
            'clientId' => 'client-1',
            'priceableId' => 'priceable-1',
            'status' => SaleLineStatus::COMPLETED,
            'quantity' => 2,
            'amount' => $this->money(2000),
            'profit' => $this->money(1000),
            'recordedAt' => $this->now(),
            'effectiveAt' => $this->now(),
            'productName' => 'Product One',
            'sku' => 'SKU-1',
            'regularUnitPrice' => $this->money(1100),
            'finalUnitPrice' => $this->money(1000),
            'promotionDiscountShare' => $this->money(100),
            'discretionaryDiscount' => $this->money(0),
            // netPaidAmount = finalUnitPrice(1000) x quantity(2) - promotionDiscountShare(100) - discretionaryDiscount(0) = 1900
            'netPaidAmount' => $this->money(1900),
            'soldAttributes' => [],
            'unitCost' => null,
            'originatingReservationLineId' => null,
        ], $overrides);
    }

    private function create(array $overrides = []): SaleLine
    {
        $args = $this->createArgs($overrides);

        return SaleLine::create(...$args);
    }

    public function test_create_succeeds_for_a_simple_line_with_no_attributes(): void
    {
        $line = $this->create();

        $this->assertSame(SaleLineType::SALE, $line->type());
        $this->assertSame([], $line->soldAttributes());
        $this->assertNull($line->unitCost());
        $this->assertTrue($line->netPaidAmount()->equals($this->money(1900)));
    }

    public function test_create_succeeds_for_a_variable_line_with_attributes(): void
    {
        $line = $this->create(['soldAttributes' => $this->soldAttributes()]);

        $this->assertSame($this->soldAttributes(), $line->soldAttributes());
    }

    public function test_create_accepts_a_null_unit_cost(): void
    {
        $line = $this->create(['unitCost' => null]);

        $this->assertNull($line->unitCost());
    }

    public function test_create_accepts_a_real_unit_cost(): void
    {
        $line = $this->create(['unitCost' => $this->money(400)]);

        $this->assertTrue($line->unitCost()->equals($this->money(400)));
    }

    public function test_create_throws_when_a_money_field_currency_does_not_match_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->create(['regularUnitPrice' => Money::fromMinorUnits(1100, 'USD')]);
    }

    public function test_create_throws_when_unit_cost_currency_does_not_match_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->create(['unitCost' => Money::fromMinorUnits(400, 'USD')]);
    }

    public function test_create_throws_when_amount_does_not_match_final_unit_price_times_quantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // createArgs()'s own amount is 2000 (finalUnitPrice 1000 x quantity 2) — off by one.
        $this->create(['amount' => $this->money(2001)]);
    }

    public function test_create_throws_when_net_paid_amount_does_not_match_the_formula(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Correct net would be 1900 (see createArgs()'s own comment) — off by one.
        $this->create(['netPaidAmount' => $this->money(1901)]);
    }

    public function test_create_throws_when_net_paid_amount_would_be_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // finalUnitPrice(1000) x quantity(2) - promotionDiscountShare(2500) - discretionaryDiscount(0) = -500.
        $this->create(['promotionDiscountShare' => $this->money(2500), 'netPaidAmount' => $this->money(-500)]);
    }

    public function test_create_throws_when_promotion_discount_share_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // netPaidAmount recomputed to keep the formula check itself from firing first:
        // finalUnitPrice(1000) x 2 - (-100) - 0 = 2100.
        $this->create(['promotionDiscountShare' => $this->money(-100), 'netPaidAmount' => $this->money(2100)]);
    }

    public function test_create_throws_when_discretionary_discount_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->create(['discretionaryDiscount' => $this->money(-50), 'netPaidAmount' => $this->money(1950)]);
    }

    public function test_create_throws_when_an_attribute_entry_is_missing_a_required_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->create(['soldAttributes' => [
            ['definitionId' => '1', 'definitionCode' => 'color', 'definitionName' => 'Color', 'valueId' => '10'],
        ]]);
    }

    /**
     * No interim regression (§3.13's own stage-2 requirement, still true
     * after stage 4b moved this check from the constructor into create()
     * itself — the check moved, it did not disappear).
     */
    public function test_create_throws_when_product_name_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->create(['productName' => '']);
    }

    // --- §3.13: Tier A structural rule for the seven new fields ----------

    public static function snapshotFieldOverrideProvider(): array
    {
        return [
            'regularUnitPrice' => [['regularUnitPrice' => Money::fromMinorUnits(100, 'EUR')]],
            'finalUnitPrice' => [['finalUnitPrice' => Money::fromMinorUnits(100, 'EUR')]],
            'promotionDiscountShare' => [['promotionDiscountShare' => Money::fromMinorUnits(0, 'EUR')]],
            'discretionaryDiscount' => [['discretionaryDiscount' => Money::fromMinorUnits(0, 'EUR')]],
            'netPaidAmount' => [['netPaidAmount' => Money::fromMinorUnits(100, 'EUR')]],
            'soldAttributes' => [['soldAttributes' => []]],
            'unitCost' => [['unitCost' => Money::fromMinorUnits(50, 'EUR')]],
        ];
    }

    #[DataProvider('snapshotFieldOverrideProvider')]
    public function test_shipping_type_with_any_non_null_snapshot_field_throws(array $override): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->construct(SaleLineType::SHIPPING, [
            'status' => SaleLineStatus::COMPLETED,
            'regularUnitPrice' => $override['regularUnitPrice'] ?? null,
            'finalUnitPrice' => $override['finalUnitPrice'] ?? null,
            'promotionDiscountShare' => $override['promotionDiscountShare'] ?? null,
            'discretionaryDiscount' => $override['discretionaryDiscount'] ?? null,
            'netPaidAmount' => $override['netPaidAmount'] ?? null,
            'soldAttributes' => $override['soldAttributes'] ?? null,
            'unitCost' => $override['unitCost'] ?? null,
        ]);
    }

    public function test_reservation_type_is_unconstrained_on_snapshot_fields(): void
    {
        $line = $this->construct(SaleLineType::RESERVATION, [
            'regularUnitPrice' => $this->money(1100),
            'finalUnitPrice' => $this->money(1000),
            'soldAttributes' => $this->soldAttributes(),
        ]);

        $this->assertTrue($line->regularUnitPrice()->equals($this->money(1100)));
        $this->assertSame($this->soldAttributes(), $line->soldAttributes());
    }

    // --- §3.13 E-D5 / §3.12 amendment: reconstitution tolerates NULLs ----

    public function test_reconstitution_of_a_sale_row_with_null_product_name_sku_and_snapshot_fields_does_not_throw(): void
    {
        $line = SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            // productName/sku AND every §3.13 field left at their null
            // defaults — this is exactly the legacy-row shape Prompt Г
            // found throwing (admin-panel-design.md §14), and §3.13
            // E-D5's own reconstitution fix.
        );

        $this->assertNull($line->productName());
        $this->assertNull($line->sku());
        $this->assertNull($line->regularUnitPrice());
        $this->assertNull($line->soldAttributes());
    }

    public function test_reconstitution_of_a_structurally_impossible_row_still_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // A SHIPPING line reconstituted with a non-null productName is
        // not "legacy data missing a later field" — it is a genuinely
        // impossible state (Tier A), which must still throw even during
        // reconstitution.
        SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: null,
            type: SaleLineType::SHIPPING,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Impossible Product Name',
        );
    }
}
