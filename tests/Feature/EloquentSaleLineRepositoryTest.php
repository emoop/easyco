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

    // --- sumQuantityEditedAwayForOriginatingLine (order-editing-design.md §4.5, stage 2 D6) ---

    /**
     * A REAL, REPORTED FINDING: unlike saveRefund() above (a real,
     * legitimate SaleLine::createRefund() call), there is currently no
     * public domain-layer path that can construct an EDIT_REVERSAL line
     * carrying its own quantity_returned — createRefund() hardcodes
     * type: REFUND, and the constructor's own assertRefundFieldsMatchType()
     * (Tier A, always enforced, including through reconstituteFromStorage())
     * still only allows the REFUND-shaped fields non-null for type REFUND.
     * Stage 2's own D5 only widened assertOriginatingSaleLineIdMatchesType()
     * — assertRefundFieldsMatchType() was explicitly out of that decision's
     * scope, so this row is written with a raw DB insert, same as this
     * file's own existing soft-delete test does for its own setup. This
     * test exercises the REPOSITORY METHOD (a read of whatever the type/
     * originating_sale_line_id/quantity_returned columns hold), which is
     * independent of how a row got there — but a real caller (Stage 3's
     * OrderEditor) cannot legally write this row yet through the domain
     * layer, and will need assertRefundFieldsMatchType() widened first.
     */
    private function saveEditReversalRaw(string $clientId, string $originatingSaleLineId, int $quantityReturned): void
    {
        $transaction = new Transaction(null, Channel::WEB);
        app(TransactionRepository::class)->save($transaction);

        DB::table('operational_sales_sale_lines')->insert([
            'transaction_id' => $transaction->id(),
            'client_id' => $clientId,
            'priceable_id' => 'variation-1',
            'type' => 'edit_reversal',
            'status' => 'completed',
            'quantity' => 1,
            'amount_minor' => 1000 * $quantityReturned,
            'amount_currency' => 'EUR',
            'profit_minor' => 0,
            'profit_currency' => 'EUR',
            'recorded_at' => $this->now(),
            'effective_at' => $this->now(),
            'originating_sale_line_id' => $originatingSaleLineId,
            'quantity_returned' => $quantityReturned,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    public function test_edited_away_returns_zero_for_a_line_never_edited(): void
    {
        $origin = $this->saleLine($this->clientId());

        $this->assertSame(0, $this->repository()->sumQuantityEditedAwayForOriginatingLine($origin->id()));
    }

    public function test_edited_away_sums_correctly_across_two_edit_reversal_lines(): void
    {
        $clientId = $this->clientId();
        $origin = $this->saleLine($clientId);

        $this->saveEditReversalRaw($clientId, $origin->id(), 2);
        $this->saveEditReversalRaw($clientId, $origin->id(), 3);

        $this->assertSame(5, $this->repository()->sumQuantityEditedAwayForOriginatingLine($origin->id()));
    }

    /**
     * §4.4's own "temporally disjoint" reasoning, proven directly: a REFUND
     * against a line and an EDIT_REVERSAL against the SAME line are summed
     * completely independently — sumQuantityEditedAwayForOriginatingLine()
     * never counts the REFUND, and sumQuantityReturnedForOriginatingLine()
     * never counts the EDIT_REVERSAL. Also proves a SALE line for the same
     * originating id contributes to neither sum.
     */
    public function test_edited_away_ignores_refund_and_sale_lines_for_the_same_originating_line(): void
    {
        $clientId = $this->clientId();
        $origin = $this->saleLine($clientId);

        $this->saveRefund($origin, 2);
        $this->saveEditReversalRaw($clientId, $origin->id(), 3);

        $this->assertSame(3, $this->repository()->sumQuantityEditedAwayForOriginatingLine($origin->id()), 'must not double-count the REFUND line.');
        $this->assertSame(2, $this->repository()->sumQuantityReturnedForOriginatingLine($origin->id()), 'must not double-count the EDIT_REVERSAL line.');
    }

    /**
     * Stage 3b — the batched twin: one grouped read, keyed by originating
     * id, lines never edited absent (not 0), empty input issues no query,
     * REFUND lines never counted, and exactly one query for many ids.
     */
    public function test_the_batched_edited_away_sum_keys_edited_lines_only_and_is_one_query(): void
    {
        $clientId = $this->clientId();
        $edited = $this->saleLine($clientId);
        $untouched = $this->saleLine($clientId);
        $refundedOnly = $this->saleLine($clientId);

        $this->saveEditReversalRaw($clientId, $edited->id(), 2);
        $this->saveEditReversalRaw($clientId, $edited->id(), 3);
        $this->saveRefund($refundedOnly, 1);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $sums = $this->repository()->sumQuantityEditedAwayForOriginatingLines([$edited->id(), $untouched->id(), $refundedOnly->id()]);

        $this->assertSame(1, $queries);
        $this->assertSame([$edited->id() => 5], $sums);

        $queries = 0;
        $this->assertSame([], $this->repository()->sumQuantityEditedAwayForOriginatingLines([]));
        $this->assertSame(0, $queries, 'an empty id list issues no query at all');
    }

    /** Stage 4a T1: the batched sum agrees with the singular one for every id, including the untouched. */
    public function test_the_batched_edited_away_sum_agrees_with_the_singular_method(): void
    {
        $clientId = $this->clientId();
        $first = $this->saleLine($clientId);
        $second = $this->saleLine($clientId);
        $untouched = $this->saleLine($clientId);

        $this->saveEditReversalRaw($clientId, $first->id(), 4);
        $this->saveEditReversalRaw($clientId, $second->id(), 1);
        $this->saveEditReversalRaw($clientId, $second->id(), 2);

        $batched = $this->repository()->sumQuantityEditedAwayForOriginatingLines([$first->id(), $second->id(), $untouched->id()]);

        foreach ([$first, $second, $untouched] as $line) {
            $this->assertSame(
                $this->repository()->sumQuantityEditedAwayForOriginatingLine($line->id()),
                $batched[$line->id()] ?? 0,
            );
        }

        $this->assertSame(0, $this->repository()->sumQuantityEditedAwayForOriginatingLine($untouched->id()), 'an untouched id is 0');
        $this->assertArrayNotHasKey($untouched->id(), $batched);
    }
}
