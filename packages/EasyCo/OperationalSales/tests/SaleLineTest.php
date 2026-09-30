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
                originatingSaleLineId: $args['originatingSaleLineId'],
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

    /**
     * order-editing-design.md §4.1 / stage 1 D3 — EDIT_REVERSAL is a real,
     * distinct SaleLineType case, unused by any factory method this stage
     * (D3's own "exists and is unused" posture), but a real value all the
     * same: a separate string from REFUND's own 'refund', never confusable
     * with it by any code that switches on the raw column value.
     */
    public function test_edit_reversal_is_a_real_distinct_case_from_refund(): void
    {
        $this->assertSame('edit_reversal', SaleLineType::EDIT_REVERSAL->value);
        $this->assertNotSame(SaleLineType::REFUND, SaleLineType::EDIT_REVERSAL);
        $this->assertNotSame(SaleLineType::REFUND->value, SaleLineType::EDIT_REVERSAL->value);
        $this->assertSame(SaleLineType::EDIT_REVERSAL, SaleLineType::from('edit_reversal'));
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
     * order-editing-design.md §4.1/§4.2, stage 2 (D5) — WIDENED, NOT
     * "except refund" any more: the allow-list is now REFUND/SALE/
     * EDIT_REVERSAL. RESERVATION/SHIPPING/INSTALLMENT_PAYMENT are the
     * three types that still refuse a non-null value — the ones this
     * provider now names accurately.
     */
    public static function typesThatStillRejectOriginatingSaleLineIdProvider(): array
    {
        return [
            'RESERVATION' => [SaleLineType::RESERVATION],
            'SHIPPING' => [SaleLineType::SHIPPING],
            'INSTALLMENT_PAYMENT' => [SaleLineType::INSTALLMENT_PAYMENT],
        ];
    }

    #[DataProvider('typesThatStillRejectOriginatingSaleLineIdProvider')]
    public function test_originating_sale_line_id_is_still_rejected_on_reservation_shipping_and_installment_payment(SaleLineType $type): void
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

    /**
     * order-editing-design.md §4.2, stage 2 (D5) — SALE now accepts it
     * too (a fresh replacement line's own lineage pointer). Constructed
     * via createNonSale() here rather than create() (which has its own,
     * separately-tested coverage further down) — createNonSale() runs
     * only Tier A, exactly what this guard is.
     */
    public function test_originating_sale_line_id_is_accepted_on_sale(): void
    {
        $line = $this->construct(SaleLineType::SALE, [
            'status' => SaleLineStatus::COMPLETED,
            'originatingSaleLineId' => 'sale-line-1',
        ]);

        $this->assertSame('sale-line-1', $line->originatingSaleLineId());
    }

    /**
     * order-editing-design.md §4.1, stage 2 (D5) — EDIT_REVERSAL also
     * accepts it: the line an edit fully/partially reverses. No factory
     * targeted EDIT_REVERSAL when this test was written (D3's own "exists
     * and is unused" — stage 3a's createEditReversal() is the first real
     * writer), so it goes through reconstituteFromStorage() directly,
     * exactly as T2 asks.
     *
     * STAGE 2'S OWN REPORTED FINDING, NOW CLOSED BY STAGE 3a: stage 2
     * deliberately loosened only assertOriginatingSaleLineIdMatchesType()
     * and reported that assertRefundFieldsMatchType() was left
     * REFUND-only, so a REAL EDIT_REVERSAL row carrying its own
     * quantityReturned/defaultRefundAmount/etc. (§4.1's "reuses the
     * entire REFUND-shaped column set") would still have thrown. Stage 3a
     * widened that second guard too, because createEditReversal() writes
     * exactly such a row —
     * test_the_refund_shaped_fields_are_accepted_on_edit_reversal_type()
     * below is the test stage 2 could not write (the fields are left null
     * here on purpose: this test proves the ONE guard it was written for,
     * and nothing else).
     */
    public function test_originating_sale_line_id_is_accepted_on_edit_reversal(): void
    {
        $line = SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::EDIT_REVERSAL,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
        );

        $this->assertSame('sale-line-1', $line->originatingSaleLineId());
        $this->assertSame(SaleLineType::EDIT_REVERSAL, $line->type());
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
     * Stage 3a adds createEditReversal() — a second, equally static
     * factory, no more a mutator than createRefund() beside it is; this
     * allow-list is what forced that addition to be conscious rather than
     * silent, exactly as the paragraph below intends.
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
            'createRefund',
            'createEditReversal',
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
            'quantityReturned',
            'defaultRefundAmount',
            'actualRefundAmount',
            'displayPriceAtReturn',
            'returnedBy',
            'returnedByName',
            'returnReason',
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

    /**
     * order-editing-design.md §4.2 / stage 1 D4 — create()'s new,
     * additive, last-and-defaulted $originatingSaleLineId parameter.
     * WITHOUT it (the default), a SALE line still round-trips through
     * reconstituteFromStorage() correctly — every existing call site
     * keeps compiling and behaving unchanged.
     */
    public function test_create_without_the_new_originating_sale_line_id_param_round_trips_through_reconstitution(): void
    {
        $line = $this->create();

        $this->assertNull($line->originatingSaleLineId());

        $reconstituted = SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: $line->transactionId(),
            clientId: $line->clientId(),
            priceableId: $line->priceableId(),
            type: $line->type(),
            status: $line->status(),
            quantity: $line->quantity(),
            amount: $line->amount(),
            profit: $line->profit(),
            recordedAt: $line->recordedAt(),
            effectiveAt: $line->effectiveAt(),
            originatingSaleLineId: $line->originatingSaleLineId(),
        );

        $this->assertNull($reconstituted->originatingSaleLineId());
    }

    /**
     * OBSOLETE ASSERTION, REWRITTEN, NOT DELETED (order-editing-design.md
     * §4.1/§4.2, stage 2 D5): stage 1's own test here asserted that
     * create() throws when passed a non-null originatingSaleLineId —
     * true only because assertOriginatingSaleLineIdMatchesType() then
     * restricted the field to type REFUND only, and create() always
     * builds type SALE. Stage 1's own report flagged this as a real
     * blocker for §4.2's "fresh replacement SALE line, pointing at the
     * line it replaces" use case, explicitly deferring the fix to this
     * stage rather than touching the guard itself (a domain-layer
     * semantic change out of schema-only scope). The guard has now
     * widened to REFUND/SALE/EDIT_REVERSAL (SaleLine.php's own updated
     * docblock) — so the same call that used to throw now succeeds, and
     * this test proves that directly rather than just asserting the old,
     * now-obsolete throw.
     */
    public function test_create_now_accepts_a_non_null_originating_sale_line_id(): void
    {
        $line = $this->create(['originatingSaleLineId' => 'sale-line-0']);

        $this->assertSame('sale-line-0', $line->originatingSaleLineId());
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

    // --- createRefund() — operational-sales-domain-design.md §3.4 revised, stage 6b-i ---

    /** A real, persisted-looking SALE line (assignId() called), the shape createRefund() requires as its origin. */
    private function persistedSaleLine(array $overrides = []): SaleLine
    {
        $line = $this->create($overrides);
        $line->assignId($overrides['id'] ?? 'sale-line-1');

        return $line;
    }

    private function refundArgs(SaleLine $originatingLine, array $overrides = []): array
    {
        return array_merge([
            'originatingLine' => $originatingLine,
            'transactionId' => 'refund-txn-1',
            'quantityReturned' => 1,
            'defaultRefundAmount' => $this->money(950),
            'returnedBy' => 'staff-1',
            'returnedByName' => 'Ana Petrova',
            'returnReason' => 'wrong size',
            'displayPriceAtReturn' => $this->money(1100),
            'recordedAt' => $this->now(),
            'effectiveAt' => $this->now(),
        ], $overrides);
    }

    private function createRefund(SaleLine $originatingLine, array $overrides = []): SaleLine
    {
        return SaleLine::createRefund(...$this->refundArgs($originatingLine, $overrides));
    }

    public function test_create_refund_succeeds_against_a_persisted_sale_line(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin);

        $this->assertSame(SaleLineType::REFUND, $refund->type());
        $this->assertSame(SaleLineStatus::COMPLETED, $refund->status());
        $this->assertSame($origin->id(), $refund->originatingSaleLineId());
    }

    public function test_create_refund_throws_when_the_originating_line_is_not_sale(): void
    {
        $origin = SaleLine::createNonSale(
            type: SaleLineType::SHIPPING,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: null,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
        $origin->assignId('shipping-line-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be type sale');

        $this->createRefund($origin);
    }

    public function test_create_refund_throws_when_the_originating_line_has_never_been_persisted(): void
    {
        $origin = $this->create(); // no assignId() call — id() is null

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must already be persisted');

        $this->createRefund($origin);
    }

    public function test_create_refund_throws_when_quantity_returned_is_zero_or_negative(): void
    {
        $origin = $this->persistedSaleLine();

        foreach ([0, -1] as $quantityReturned) {
            try {
                $this->createRefund($origin, ['quantityReturned' => $quantityReturned]);
                $this->fail("quantityReturned={$quantityReturned} must be refused.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('must be a positive integer', $exception->getMessage());
            }
        }
    }

    public function test_create_refund_throws_when_quantity_returned_exceeds_the_originating_lines_own_quantity(): void
    {
        $origin = $this->persistedSaleLine(['quantity' => 2, 'amount' => $this->money(2000)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not exceed');

        $this->createRefund($origin, ['quantityReturned' => 3]);
    }

    public function test_create_refund_accepts_quantity_returned_equal_to_the_full_original_quantity(): void
    {
        $origin = $this->persistedSaleLine(['quantity' => 2, 'amount' => $this->money(2000)]);

        $refund = $this->createRefund($origin, ['quantityReturned' => 2]);

        $this->assertSame(2, $refund->quantityReturned());
    }

    public function test_create_refund_throws_when_default_refund_amount_is_zero_or_negative(): void
    {
        $origin = $this->persistedSaleLine();

        foreach ([$this->money(0), Money::fromMinorUnits(-100, 'EUR')] as $amount) {
            try {
                $this->createRefund($origin, ['defaultRefundAmount' => $amount]);
                $this->fail('a zero/negative defaultRefundAmount must be refused.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('must be positive', $exception->getMessage());
            }
        }
    }

    public function test_create_refund_throws_when_default_refund_amount_currency_does_not_match_the_originating_line(): void
    {
        $origin = $this->persistedSaleLine(); // EUR, per $this->money()'s own default

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("currency");

        $this->createRefund($origin, ['defaultRefundAmount' => Money::fromMinorUnits(950, 'USD')]);
    }

    /**
     * order-lifecycle-design.md §14 Q6's own resolution: quantity on the
     * BUILT row is the ORIGIN's own quantity, never quantityReturned —
     * the two numbers stay independently readable without a join.
     */
    public function test_create_refund_sets_quantity_to_the_originating_lines_own_quantity_not_quantity_returned(): void
    {
        // finalUnitPrice(1000) x quantity(5) - promotionDiscountShare(100) - discretionaryDiscount(0) = 4900.
        $origin = $this->persistedSaleLine(['quantity' => 5, 'amount' => $this->money(5000), 'netPaidAmount' => $this->money(4900)]);

        $refund = $this->createRefund($origin, ['quantityReturned' => 2]);

        $this->assertSame(5, $refund->quantity(), 'quantity must be the ORIGIN\'s own quantity');
        $this->assertSame(2, $refund->quantityReturned(), 'quantityReturned is the separate, independently-readable count');
    }

    public function test_create_refund_sets_amount_to_the_positive_default_refund_amount(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin, ['defaultRefundAmount' => $this->money(950)]);

        $this->assertTrue($refund->amount()->equals($this->money(950)));
        $this->assertTrue($refund->amount()->isPositive(), 'amount is stored positive, not negated (consistent with PaymentRefund)');
    }

    public function test_create_refund_sets_actual_refund_amount_equal_to_default_with_no_override_exposed(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin, ['defaultRefundAmount' => $this->money(950)]);

        $this->assertTrue($refund->actualRefundAmount()->equals($this->money(950)));
        $this->assertTrue($refund->defaultRefundAmount()->equals($refund->actualRefundAmount()));
    }

    /** An explicit, reported scope cut (this stage's own final report) — see createRefund()'s own docblock. */
    public function test_create_refund_sets_profit_to_zero(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin);

        $this->assertTrue($refund->profit()->isZero());
        $this->assertSame('EUR', $refund->profit()->currency()->code());
    }

    /**
     * §3.4 revised: a REFUND line never duplicates the SALE line's
     * snapshot fields — it resolves them through originatingSaleLineId
     * instead. Every one of these stays NULL on the built row, even
     * though the origin has them all set.
     */
    public function test_create_refund_leaves_every_snapshot_field_null_even_though_the_origin_has_them_set(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin);

        $this->assertNull($refund->productName());
        $this->assertNull($refund->sku());
        $this->assertNull($refund->soldAttributes());
        $this->assertNull($refund->regularUnitPrice());
        $this->assertNull($refund->finalUnitPrice());
        $this->assertNull($refund->promotionDiscountShare());
        $this->assertNull($refund->discretionaryDiscount());
        $this->assertNull($refund->netPaidAmount());
        $this->assertNull($refund->unitCost());
    }

    public function test_create_refund_carries_the_actor_and_reason_verbatim(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin, [
            'returnedBy' => 'staff-42',
            'returnedByName' => 'Ivan Ivanov',
            'returnReason' => 'defective',
        ]);

        $this->assertSame('staff-42', $refund->returnedBy());
        $this->assertSame('Ivan Ivanov', $refund->returnedByName());
        $this->assertSame('defective', $refund->returnReason());
    }

    public function test_create_refund_accepts_a_null_display_price_at_return_and_null_reason_and_null_actor(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin, [
            'returnedBy' => null,
            'returnedByName' => null,
            'returnReason' => null,
            'displayPriceAtReturn' => null,
        ]);

        $this->assertNull($refund->returnedBy());
        $this->assertNull($refund->returnedByName());
        $this->assertNull($refund->returnReason());
        $this->assertNull($refund->displayPriceAtReturn());
    }

    public function test_create_refund_carries_a_real_display_price_at_return(): void
    {
        $origin = $this->persistedSaleLine();

        $refund = $this->createRefund($origin, ['displayPriceAtReturn' => $this->money(1150)]);

        $this->assertTrue($refund->displayPriceAtReturn()->equals($this->money(1150)));
    }

    /** Tier A: every REFUND field must be null for a non-REFUND type. */
    public function test_refund_fields_must_be_null_for_sale_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must all be null');

        // create() has no parameter for these — reach the constructor's
        // own Tier A guard through reconstituteFromStorage(), the one
        // public path that accepts every field positionally.
        SaleLine::reconstituteFromStorage(
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
            quantityReturned: 1,
        );
    }

    public function test_refund_fields_must_be_null_for_shipping_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must all be null');

        SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: null,
            type: SaleLineType::SHIPPING,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            returnReason: 'should not be allowed here',
        );
    }

    public function test_refund_fields_must_be_null_for_installment_payment_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must all be null');

        SaleLine::reconstituteFromStorage(
            id: 'line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: null,
            type: SaleLineType::INSTALLMENT_PAYMENT,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            returnedBy: 'staff-1',
        );
    }

    /** Tier A: REFUND itself is unconstrained at this tier — every field accepted null or set. */
    public function test_refund_fields_are_unconstrained_for_refund_type(): void
    {
        $line = SaleLine::createNonSale(
            type: SaleLineType::REFUND,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
        );

        $this->assertNull($line->quantityReturned());
        $this->assertNull($line->defaultRefundAmount());
        $this->assertNull($line->returnedBy());
    }

    public function test_the_six_refund_fields_round_trip_through_reconstitute_from_storage(): void
    {
        $line = SaleLine::reconstituteFromStorage(
            id: 'refund-line-1',
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::REFUND,
            status: SaleLineStatus::COMPLETED,
            quantity: 5,
            amount: $this->money(950),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
            quantityReturned: 2,
            defaultRefundAmount: $this->money(950),
            actualRefundAmount: $this->money(900),
            displayPriceAtReturn: $this->money(1100),
            returnedBy: 'staff-1',
            returnedByName: 'Ana Petrova',
            returnReason: 'wrong size',
        );

        $this->assertSame(2, $line->quantityReturned());
        $this->assertTrue($line->defaultRefundAmount()->equals($this->money(950)));
        $this->assertTrue($line->actualRefundAmount()->equals($this->money(900)), 'a legacy/operator-overridden row may legitimately disagree with defaultRefundAmount');
        $this->assertTrue($line->displayPriceAtReturn()->equals($this->money(1100)));
        $this->assertSame('staff-1', $line->returnedBy());
        $this->assertSame('Ana Petrova', $line->returnedByName());
        $this->assertSame('wrong size', $line->returnReason());
    }

    // --- createEditReversal() — order-editing-design.md §4.1/§4.2/§4.3, stage 3a (D3) ---

    /**
     * A real, persisted-looking SALE line as storage hands one back: a
     * persisted id plus the full §3.13 snapshot. Overridable per field so
     * a test can describe a row the write-time factories could never have
     * produced (a corrupt negative net, a foreign-currency net) as well
     * as one they can (a legitimately free, fully discounted zero net).
     *
     * @param array<string, mixed> $overrides
     */
    private function reconstitutedSaleLine(array $overrides = []): SaleLine
    {
        $args = array_merge([
            'id' => 'sale-line-1',
            'transactionId' => 'txn-1',
            'clientId' => 'client-1',
            'priceableId' => 'priceable-1',
            'quantity' => 2,
            'amount' => $this->money(2000),
            'profit' => $this->money(1000),
            'productName' => 'Product One',
            'sku' => 'SKU-1',
            'regularUnitPrice' => $this->money(1100),
            'finalUnitPrice' => $this->money(1000),
            'promotionDiscountShare' => $this->money(100),
            'discretionaryDiscount' => $this->money(0),
            'netPaidAmount' => $this->money(1900),
            'soldAttributes' => [],
            'unitCost' => null,
        ], $overrides);

        return SaleLine::reconstituteFromStorage(
            id: $args['id'],
            transactionId: $args['transactionId'],
            clientId: $args['clientId'],
            priceableId: $args['priceableId'],
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: $args['quantity'],
            amount: $args['amount'],
            profit: $args['profit'],
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
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

    /** A SALE row exactly as a pre-§3.13 write left it: no snapshot fields recorded at all, so netPaidAmount is null — not zero. */
    private function legacySaleLine(): SaleLine
    {
        return SaleLine::reconstituteFromStorage(
            id: 'legacy-sale-1',
            transactionId: 'txn-old',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 2,
            amount: $this->money(2000),
            profit: $this->money(1000),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
    }

    /** A legitimately FREE SALE line: 2 x 1000 fully covered by a 2000 promotion share, so netPaidAmount is exactly zero, never null. */
    private function fullyDiscountedSaleLine(): SaleLine
    {
        return $this->reconstitutedSaleLine([
            'profit' => $this->money(0),
            'regularUnitPrice' => $this->money(1000),
            'promotionDiscountShare' => $this->money(2000),
            'netPaidAmount' => $this->money(0),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function editReversalArgs(SaleLine $originatingLine, array $overrides = []): array
    {
        return array_merge([
            'originatingLine' => $originatingLine,
            'transactionId' => 'edit-txn-1',
            'editedBy' => 'staff-1',
            'editedByName' => 'Ana Petrova',
            'reason' => 'customer changed size',
            'displayPriceAtEdit' => $this->money(1100),
            'recordedAt' => $this->now(),
            'effectiveAt' => $this->now(),
        ], $overrides);
    }

    private function createEditReversal(SaleLine $originatingLine, array $overrides = []): SaleLine
    {
        return SaleLine::createEditReversal(...$this->editReversalArgs($originatingLine, $overrides));
    }

    public function test_create_edit_reversal_succeeds_against_a_persisted_sale_line(): void
    {
        $origin = $this->persistedSaleLine();

        $reversal = $this->createEditReversal($origin);

        $this->assertNull($reversal->id(), 'a reversal is a brand new line — only the origin is persisted');
        $this->assertSame(SaleLineType::EDIT_REVERSAL, $reversal->type());
        $this->assertSame(SaleLineStatus::COMPLETED, $reversal->status(), 'a settled ledger fact, exactly like a REFUND — never PENDING');
        $this->assertSame($origin->id(), $reversal->originatingSaleLineId());
        $this->assertSame('edit-txn-1', $reversal->transactionId());
        $this->assertSame($origin->clientId(), $reversal->clientId());
        $this->assertSame($origin->priceableId(), $reversal->priceableId(), 'the stock consequence is the SAME variation the origin sold');
        $this->assertEquals($this->now(), $reversal->recordedAt());
        $this->assertEquals($this->now(), $reversal->effectiveAt());
    }

    public function test_create_edit_reversal_reverses_the_origins_own_full_quantity(): void
    {
        // finalUnitPrice(1000) x quantity(4) - promotionDiscountShare(100) = 3900.
        $origin = $this->persistedSaleLine([
            'quantity' => 4,
            'amount' => $this->money(4000),
            'netPaidAmount' => $this->money(3900),
        ]);

        $reversal = $this->createEditReversal($origin);

        $this->assertSame(4, $reversal->quantity(), "quantity is the ORIGIN's own quantity");
        $this->assertSame(4, $reversal->quantityReturned(), 'the FULL quantity — §4.2 allows no partial edit reversal, so no caller-supplied count could ever disagree with quantity()');
    }

    public function test_create_edit_reversal_sets_amount_and_both_refund_amounts_to_the_origins_net_paid_amount(): void
    {
        $origin = $this->persistedSaleLine(); // amount 2000, netPaidAmount 1900

        $reversal = $this->createEditReversal($origin);

        $this->assertTrue($reversal->amount()->equals($this->money(1900)), 'netPaidAmount — what the customer actually paid — never the pre-discount amount');
        $this->assertTrue($reversal->amount()->isPositive(), 'released money is stored POSITIVE, exactly as createRefund() stores it');
        $this->assertTrue($reversal->defaultRefundAmount()->equals($this->money(1900)));
        $this->assertTrue($reversal->actualRefundAmount()->equals($this->money(1900)), '§4.3: no money is captured at edit time, so the two columns never disagree on an EDIT_REVERSAL this factory wrote');
    }

    public function test_create_edit_reversal_sets_profit_to_zero_in_the_released_currencys_own_units(): void
    {
        $origin = $this->persistedSaleLine();

        $reversal = $this->createEditReversal($origin);

        $this->assertTrue($reversal->profit()->isZero());
        $this->assertSame('EUR', $reversal->profit()->currency()->code(), "zero in the origin's own currency, never a bare zero");
    }

    public function test_create_edit_reversal_leaves_every_snapshot_field_null(): void
    {
        $origin = $this->persistedSaleLine();

        $reversal = $this->createEditReversal($origin);

        $this->assertNull($reversal->productName(), 'the reversal snapshots nothing — it says only "this line is gone"');
        $this->assertNull($reversal->sku());
        $this->assertNull($reversal->regularUnitPrice());
        $this->assertNull($reversal->finalUnitPrice());
        $this->assertNull($reversal->promotionDiscountShare());
        $this->assertNull($reversal->discretionaryDiscount());
        $this->assertNull($reversal->netPaidAmount(), "§4.6: only a SALE line carries the §3.13 snapshot — the reversal resolves the origin's via originatingSaleLineId()");
        $this->assertNull($reversal->soldAttributes());
        $this->assertNull($reversal->unitCost());
        $this->assertNull($reversal->originatingReservationLineId(), 'only a SALE line settles a reservation');
    }

    public function test_create_edit_reversal_carries_the_editor_and_reason_in_the_refund_shaped_columns(): void
    {
        $origin = $this->persistedSaleLine();

        $reversal = $this->createEditReversal($origin);

        $this->assertSame('staff-1', $reversal->returnedBy(), '§4.1 reuses the REFUND column set; `type` tells a reader which meaning applies');
        $this->assertSame('Ana Petrova', $reversal->returnedByName());
        $this->assertSame('customer changed size', $reversal->returnReason());
        $this->assertTrue($reversal->displayPriceAtReturn()->equals($this->money(1100)));
    }

    public function test_create_edit_reversal_accepts_a_null_editor_reason_and_display_price(): void
    {
        $origin = $this->persistedSaleLine();

        $reversal = $this->createEditReversal($origin, [
            'editedBy' => null,
            'editedByName' => null,
            'reason' => null,
            'displayPriceAtEdit' => null,
        ]);

        $this->assertNull($reversal->returnedBy());
        $this->assertNull($reversal->returnedByName());
        $this->assertNull($reversal->returnReason());
        $this->assertNull($reversal->displayPriceAtReturn());
        $this->assertTrue($reversal->amount()->equals($this->money(1900)), 'the money facts are never optional, only the narration is');
    }

    public function test_create_edit_reversal_throws_when_the_originating_line_is_not_sale(): void
    {
        $origin = $this->persistedSaleLine();
        $refund = $this->createRefund($origin); // a real REFUND row — persisted, but not a SALE line
        $refund->assignId('refund-line-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SaleLine::createEditReversal(): originatingLine must be type sale, got refund.');

        $this->createEditReversal($refund);
    }

    public function test_create_edit_reversal_throws_when_the_originating_line_has_never_been_persisted(): void
    {
        $origin = $this->create(); // a perfectly valid SALE line, but with no id: never stored

        $this->assertNull($origin->id());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SaleLine::createEditReversal(): originatingLine must already be persisted (have a real id)');

        $this->createEditReversal($origin);
    }

    public function test_create_edit_reversal_refuses_a_legacy_origin_with_no_recorded_net_paid_amount(): void
    {
        $origin = $this->legacySaleLine();

        $this->assertNull($origin->netPaidAmount(), 'the row predates §3.13: no snapshot at all, so netPaidAmount is genuinely unknown — not zero');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SaleLine::createEditReversal(): originatingLine "legacy-sale-1" has no netPaidAmount recorded');

        $this->createEditReversal($origin);
    }

    public function test_create_edit_reversal_refuses_a_corrupt_negative_net_paid_amount(): void
    {
        // reconstituteFromStorage()'s Tier A deliberately does not re-derive
        // §3.13's invariants, so this row can exist; the factory refuses to
        // turn it into a "money released" ledger fact.
        $origin = $this->reconstitutedSaleLine(['netPaidAmount' => Money::fromMinorUnits(-100, 'EUR')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SaleLine::createEditReversal(): netAmountReduction must not be negative');

        $this->createEditReversal($origin);
    }

    public function test_create_edit_reversal_refuses_a_net_paid_amount_in_another_currency_than_the_originating_amount(): void
    {
        $origin = $this->reconstitutedSaleLine(['netPaidAmount' => Money::fromMinorUnits(1900, 'USD')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("SaleLine::createEditReversal(): netAmountReduction's currency (USD) must match the originating line's own currency (EUR).");

        $this->createEditReversal($origin);
    }

    public function test_create_edit_reversal_accepts_a_fully_discounted_origin_and_records_a_zero_amount(): void
    {
        $origin = $this->fullyDiscountedSaleLine();
        $this->assertTrue($origin->netPaidAmount()->isZero(), 'zero, not null: §3.13 was shipped for this row');

        $reversal = $this->createEditReversal($origin);

        $this->assertTrue($reversal->amount()->isZero(), 'D2: a free line is still editable, so it is still reversible — a zero release is a fact, not an error');
        $this->assertFalse($reversal->amount()->isNegative(), 'never a negative release');
        $this->assertTrue($reversal->defaultRefundAmount()->isZero());
        $this->assertSame(2, $reversal->quantityReturned(), 'the stock half of the reversal is NOT zero — only the money half is');
    }

    public function test_create_refund_still_refuses_the_fully_discounted_origin_that_create_edit_reversal_accepts(): void
    {
        // D2's own rationale as a contrast in one place: the two factories
        // reach opposite-but-both-correct answers for the same zero-net row,
        // because createRefund()'s defaultRefundAmount is a CHOICE a human
        // makes for a return, while a reversal's is DERIVED.
        $origin = $this->fullyDiscountedSaleLine();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('defaultRefundAmount must be positive');

        $this->createRefund($origin, ['defaultRefundAmount' => $this->money(0)]);
    }

    /**
     * D1 — the guard widening itself: the seven REFUND-shaped fields are
     * unconstrained for EDIT_REVERSAL (assertRefundFieldsMatchType()),
     * exactly as this file's own
     * test_the_six_refund_fields_round_trip_through_reconstitute_from_storage()
     * shows they are for REFUND. Without this widening, the row
     * createEditReversal() builds (§4.1's "reuses the entire
     * REFUND-shaped column set") could never be read back.
     */
    public function test_the_refund_shaped_fields_are_accepted_on_edit_reversal_type(): void
    {
        $line = SaleLine::reconstituteFromStorage(
            id: 'reversal-1',
            transactionId: 'edit-txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::EDIT_REVERSAL,
            status: SaleLineStatus::COMPLETED,
            quantity: 2,
            amount: $this->money(1900),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
            quantityReturned: 2,
            defaultRefundAmount: $this->money(1900),
            actualRefundAmount: $this->money(1800),
            displayPriceAtReturn: $this->money(1100),
            returnedBy: 'staff-1',
            returnedByName: 'Ana Petrova',
            returnReason: 'customer changed size',
        );

        $this->assertSame(SaleLineType::EDIT_REVERSAL, $line->type());
        $this->assertSame(2, $line->quantityReturned());
        $this->assertTrue($line->defaultRefundAmount()->equals($this->money(1900)));
        $this->assertTrue($line->actualRefundAmount()->equals($this->money(1800)), 'a stored row may legitimately disagree with defaultRefundAmount — only the factory keeps them equal');
        $this->assertTrue($line->displayPriceAtReturn()->equals($this->money(1100)));
        $this->assertSame('staff-1', $line->returnedBy());
        $this->assertSame('Ana Petrova', $line->returnedByName());
        $this->assertSame('customer changed size', $line->returnReason());
    }

    /**
     * The strongest end-to-end proof available without a database: the row
     * the factory builds is itself a legal row. Feeding each of the
     * factory's own outputs back through reconstituteFromStorage() — the
     * same path SaleLineRepository uses when it rehydrates a line — must
     * not throw, and must hand back the same facts. This is what makes
     * §4.1's "reuse the REFUND column set, no new columns, no migration"
     * claim checkable here rather than only in Stage 3b's write path.
     */
    public function test_the_row_create_edit_reversal_builds_can_be_read_back_through_reconstitute_from_storage(): void
    {
        $reversal = $this->createEditReversal($this->persistedSaleLine());

        $readBack = SaleLine::reconstituteFromStorage(
            id: 'reversal-1',
            transactionId: $reversal->transactionId(),
            clientId: $reversal->clientId(),
            priceableId: $reversal->priceableId(),
            type: $reversal->type(),
            status: $reversal->status(),
            quantity: $reversal->quantity(),
            amount: $reversal->amount(),
            profit: $reversal->profit(),
            recordedAt: $reversal->recordedAt(),
            effectiveAt: $reversal->effectiveAt(),
            originatingSaleLineId: $reversal->originatingSaleLineId(),
            quantityReturned: $reversal->quantityReturned(),
            defaultRefundAmount: $reversal->defaultRefundAmount(),
            actualRefundAmount: $reversal->actualRefundAmount(),
            displayPriceAtReturn: $reversal->displayPriceAtReturn(),
            returnedBy: $reversal->returnedBy(),
            returnedByName: $reversal->returnedByName(),
            returnReason: $reversal->returnReason(),
        );

        $this->assertSame(SaleLineType::EDIT_REVERSAL, $readBack->type());
        $this->assertSame('sale-line-1', $readBack->originatingSaleLineId());
        $this->assertSame(2, $readBack->quantity());
        $this->assertSame(2, $readBack->quantityReturned());
        $this->assertTrue($readBack->amount()->equals($this->money(1900)));
        $this->assertTrue($readBack->profit()->isZero());
        $this->assertNull($readBack->netPaidAmount(), 'the reversal carries no §3.13 snapshot of its own, even after a round trip');
        $this->assertSame('staff-1', $readBack->returnedBy());
    }
}
