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

        $line = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: $args['priceableId'],
            type: $type,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: $args['productName'],
            sku: $args['sku'],
        );

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

    #[DataProvider('priceableIdRequiredTypesProvider')]
    public function test_priceable_id_is_required_for_sale_reservation_and_refund(SaleLineType $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: null,
            type: $type,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
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

        new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: $type,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
    }

    public function test_quantity_of_zero_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::PENDING,
            quantity: 0,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
    }

    public function test_negative_quantity_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::PENDING,
            quantity: -1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );
    }

    public static function nonRefundTypesProvider(): array
    {
        return [
            'SALE' => [SaleLineType::SALE],
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

        new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: $args['priceableId'],
            type: $type,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
        );
    }

    public function test_originating_sale_line_id_is_accepted_on_refund(): void
    {
        $line = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::REFUND,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
        );

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

        new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: $args['priceableId'],
            type: $type,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingReservationLineId: 'reservation-line-1',
        );
    }

    public function test_originating_reservation_line_id_is_accepted_on_sale(): void
    {
        $line = new SaleLine(
            id: null,
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
            originatingReservationLineId: 'reservation-line-1',
            productName: 'Product One',
            sku: 'SKU-1',
        );

        $this->assertSame('reservation-line-1', $line->originatingReservationLineId());
    }

    private function placeholderLine(): SaleLine
    {
        return new SaleLine(
            id: null,
            transactionId: '',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
        );
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
        $line = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
        );

        $this->expectException(\LogicException::class);
        $line->assignTransactionId('txn-2');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $line = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
        );

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
     * constructor, the plain accessors listed below, assignId(), and
     * assignTransactionId() (a narrow, one-time structural-reference
     * backfill — see the class docblock for why that's not a violation
     * of §3.2) — no setter or other mutation method exists on this
     * class. Written as a Reflection-based allow-list so that adding any
     * new public method to SaleLine in the future forces a conscious
     * update to this test, rather than silently slipping a mutator past
     * the class's central invariant.
     */
    public function test_no_mutation_method_exists_beyond_assign_id(): void
    {
        $expectedPublicMethods = [
            '__construct',
            'reconstituteFromStorage',
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
     * operational-sales-domain-design.md §3.12: productName/sku required
     * only for SaleLineType::SALE.
     */
    public function test_sale_type_with_real_product_name_and_sku_succeeds(): void
    {
        $line = new SaleLine(
            id: null,
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
            productName: 'Product One',
            sku: 'SKU-1',
        );

        $this->assertSame('Product One', $line->productName());
        $this->assertSame('SKU-1', $line->sku());
    }

    public static function nullProductNameOrSkuProvider(): array
    {
        return [
            'null productName' => [null, 'SKU-1'],
            'null sku' => ['Product One', null],
            'both null' => [null, null],
        ];
    }

    #[DataProvider('nullProductNameOrSkuProvider')]
    public function test_sale_type_with_a_null_product_name_or_sku_throws(?string $productName, ?string $sku): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SaleLine(
            id: null,
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
            productName: $productName,
            sku: $sku,
        );
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

        new SaleLine(
            id: null,
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
            productName: $productName,
            sku: $sku,
        );
    }

    public function test_shipping_type_with_both_null_succeeds(): void
    {
        $line = new SaleLine(
            id: null,
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
            productName: null,
            sku: null,
        );

        $this->assertNull($line->productName());
        $this->assertNull($line->sku());
    }

    public function test_reservation_type_is_unconstrained_on_product_name_and_sku(): void
    {
        $withoutSnapshot = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::RESERVATION,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );

        $withSnapshot = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::RESERVATION,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
        );

        $this->assertNull($withoutSnapshot->productName());
        $this->assertSame('Product One', $withSnapshot->productName());
    }

    public function test_refund_type_is_unconstrained_on_product_name_and_sku(): void
    {
        $withoutSnapshot = new SaleLine(
            id: null,
            transactionId: 'txn-1',
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::REFUND,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            originatingSaleLineId: 'sale-line-1',
        );

        $this->assertNull($withoutSnapshot->productName());
        $this->assertNull($withoutSnapshot->sku());
    }
}
