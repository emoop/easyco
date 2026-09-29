<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * operational-sales-domain-design.md §3.4 (revised) — R7's own read
 * (order-lifecycle-design.md §2.2): SUM(quantity_returned) across REFUND
 * lines sharing an originating_sale_line_id, real DB, real constraints.
 */
class EloquentSaleLineRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): SaleLineRepository
    {
        return app(SaleLineRepository::class);
    }

    private function clientId(): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        return $client->id();
    }

    private function money(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, 'EUR');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-29 09:00:00');
    }

    /** A real, persisted SALE line, quantity 5, net 5000. */
    private function saleLine(string $clientId): SaleLine
    {
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: 'variation-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 5,
            amount: $this->money(5000),
            profit: $this->money(1000),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: $this->money(1000),
            finalUnitPrice: $this->money(1000),
            promotionDiscountShare: $this->money(0),
            discretionaryDiscount: $this->money(0),
            netPaidAmount: $this->money(5000),
            soldAttributes: [],
        ));
        app(TransactionRepository::class)->save($transaction);

        return $transaction->saleLines()[0];
    }

    private function saveRefund(SaleLine $origin, int $quantityReturned): void
    {
        $refund = SaleLine::createRefund(
            originatingLine: $origin,
            transactionId: '',
            quantityReturned: $quantityReturned,
            defaultRefundAmount: $this->money(1000 * $quantityReturned),
            returnedBy: null,
            returnedByName: null,
            returnReason: null,
            displayPriceAtReturn: null,
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine($refund);
        app(TransactionRepository::class)->save($transaction);
    }

    public function test_it_returns_zero_for_a_line_never_refunded(): void
    {
        $origin = $this->saleLine($this->clientId());

        $this->assertSame(0, $this->repository()->sumQuantityReturnedForOriginatingLine($origin->id()));
    }

    public function test_it_sums_correctly_across_two_prior_partial_refund_lines(): void
    {
        $clientId = $this->clientId();
        $origin = $this->saleLine($clientId);

        $this->saveRefund($origin, 1);
        $this->saveRefund($origin, 2);

        $this->assertSame(3, $this->repository()->sumQuantityReturnedForOriginatingLine($origin->id()));
    }

    /**
     * Ignores SALE/RESERVATION/SHIPPING lines entirely, and REFUND lines
     * belonging to a DIFFERENT originating line — the sum is scoped by
     * both type=refund AND originating_sale_line_id together.
     */
    public function test_it_ignores_non_refund_lines_and_refund_lines_for_a_different_originating_line(): void
    {
        $clientId = $this->clientId();
        $origin = $this->saleLine($clientId);
        $otherOrigin = $this->saleLine($clientId);

        $this->saveRefund($origin, 2);
        $this->saveRefund($otherOrigin, 4);

        // A RESERVATION line (never a REFUND) attached to the same client
        // — must never contribute to either sum.
        $reservation = new Transaction(null, Channel::WEB);
        $reservation->addSaleLine(SaleLine::createNonSale(
            type: \EasyCo\OperationalSales\Enums\SaleLineType::RESERVATION,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'variation-2',
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(1000),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        ));
        app(TransactionRepository::class)->save($reservation);

        $this->assertSame(2, $this->repository()->sumQuantityReturnedForOriginatingLine($origin->id()));
        $this->assertSame(4, $this->repository()->sumQuantityReturnedForOriginatingLine($otherOrigin->id()));
    }
}
