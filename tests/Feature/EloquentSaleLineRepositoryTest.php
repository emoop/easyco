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
use Illuminate\Support\Facades\DB;
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

    // --- the batched twin (order-lifecycle-design.md §8.4, stage 7c-1) --------

    /**
     * The SAME read as the three tests above, stated for many lines at once.
     * Two properties are the whole point of the second shape, and both are
     * asserted here:
     *
     *  - an id with NO REFUND lines is ABSENT from the map, never present with
     *    0 — the "never refunded" default belongs to the caller
     *    (OrderAdminReader's own `?? 0`), so this read never materialises rows
     *    it did not read (the contract's own docblock);
     *  - the two shapes AGREE for the same line: the singular method still
     *    answers the same number the plural map carries, because it is the same
     *    condition stated once for one id and once for many.
     */
    public function test_the_batched_sum_keys_the_lines_that_have_refunds_and_omits_the_ones_that_have_none(): void
    {
        $clientId = $this->clientId();
        $neverRefunded = $this->saleLine($clientId);
        $partiallyReturned = $this->saleLine($clientId);
        $fullyReturned = $this->saleLine($clientId);

        $this->saveRefund($partiallyReturned, 1);
        $this->saveRefund($partiallyReturned, 2);
        $this->saveRefund($fullyReturned, 5);

        $sums = $this->repository()->sumQuantityReturnedForOriginatingLines([
            $neverRefunded->id(),
            $partiallyReturned->id(),
            $fullyReturned->id(),
        ]);

        $this->assertSame(3, $sums[$partiallyReturned->id()], 'both partial refunds of that line, summed');
        $this->assertSame(5, $sums[$fullyReturned->id()]);
        $this->assertArrayNotHasKey(
            $neverRefunded->id(),
            $sums,
            'a line with no REFUND lines is ABSENT, never present with 0'
        );

        $this->assertSame(
            0,
            $this->repository()->sumQuantityReturnedForOriginatingLine($neverRefunded->id()),
            'the single-line shape answers 0 for the same line — the two shapes state one read, not two'
        );
    }

    /**
     * An EMPTY id list is answered without touching the database at all — the
     * reason an order with no lines adds no query to the Orders admin View page
     * (OrderAdminReaderEventsTest measures that end of it, through
     * OrderAdminReader itself). Asserted on the query count, not merely on the
     * [], because a WHERE IN () that still round-trips is exactly the cost this
     * short-circuit exists to avoid.
     */
    public function test_the_batched_sum_issues_no_query_for_an_empty_id_list(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $sums = $this->repository()->sumQuantityReturnedForOriginatingLines([]);

        DB::flushQueryLog();

        $this->assertSame([], $sums);
        $this->assertSame(0, $queries, 'nothing to ask about must cost no query at all');
    }

    /**
     * The SoftDeletes scope is NOT bypassed, deliberately (see
     * EloquentSaleLineRepository's own docblock for the reasoning): a REFUND
     * line is the record that its units came back, so a soft-delete — a
     * correction — must stop counting toward "already returned". Pinned for the
     * BATCHED read too, because it is the shape the Orders admin page actually
     * uses: a correction that kept counting would lower §8.4's
     * remainingReturnable on the page while R7's own locked read no longer saw
     * it, which is the one way this number and the write path's decision could
     * disagree.
     */
    public function test_a_soft_deleted_refund_line_stops_counting_in_both_shapes(): void
    {
        $origin = $this->saleLine($this->clientId());
        $this->saveRefund($origin, 4);

        $this->assertSame(4, $this->repository()->sumQuantityReturnedForOriginatingLine($origin->id()));

        $refundId = DB::table('operational_sales_sale_lines')
            ->where('originating_sale_line_id', $origin->id())
            ->value('id');

        $this->assertNotNull($refundId, 'the fixture wrote a real REFUND row');

        DB::table('operational_sales_sale_lines')
            ->where('id', $refundId)
            ->update(['deleted_at' => '2026-09-29 09:00:00']);

        $this->assertSame(0, $this->repository()->sumQuantityReturnedForOriginatingLine($origin->id()));
        $this->assertSame([], $this->repository()->sumQuantityReturnedForOriginatingLines([$origin->id()]));
    }
}
