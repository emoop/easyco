<?php

namespace Tests\Feature;

use App\Services\Exceptions\ReturnExceedsRemainingQuantityException;
use App\Services\ReturnGoodsRecorder;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §7.1/§7.2 items 1-2 (stage 6b-i) —
 * App\Services\ReturnGoodsRecorder::record(). Every call here wraps the
 * recorder in the TEST's OWN DB::transaction(), simulating the future
 * caller (OrderStatusChanger::recordReturn(), stage 6b-ii) that will hold
 * the order's own row lock around this call — this class assumes it is
 * already inside one and never opens its own (see its own docblock).
 *
 * `stock_levels.variation_id` is a real integer FK (not a plain string —
 * SaleLine.priceableId is untyped, but Inventory's own table genuinely
 * references Catalog), so every fixture line here targets a REAL, freshly
 * created Catalog Product's Universal variation, exactly the shape
 * CartAfterCheckoutTest's own fixtures already use for the same reason.
 */
class ReturnGoodsRecorderTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function recorder(): ReturnGoodsRecorder
    {
        return app(ReturnGoodsRecorder::class);
    }

    private function money(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, 'EUR');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-29 10:00:00');
    }

    private function clientId(): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        return $client->id();
    }

    /** returned_by is a real FK to staff (not a plain string) — a real Staff row for the fixture that exercises it. */
    private function staffId(): string
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('returns.operator@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        return $staff->id();
    }

    /** A real, persisted Catalog Product's Universal variation id — the same fixture shape CartAfterCheckoutTest already uses. */
    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "return-goods-recorder-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    /** A real, persisted SALE line for $variationId, quantity $quantity, net = 1000 per unit, no discounts. */
    private function saleLine(string $clientId, string $variationId, int $quantity = 5): SaleLine
    {
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: $quantity,
            amount: $this->money(1000 * $quantity),
            profit: $this->money(200 * $quantity),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: $this->money(1000),
            finalUnitPrice: $this->money(1000),
            promotionDiscountShare: $this->money(0),
            discretionaryDiscount: $this->money(0),
            netPaidAmount: $this->money(1000 * $quantity),
            soldAttributes: [],
        ));
        app(TransactionRepository::class)->save($transaction);

        return $transaction->saleLines()[0];
    }

    /** A REFUND line already present, via D5's repository target directly — not a second recorder call. */
    private function savePriorRefund(SaleLine $origin, int $quantityReturned): void
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

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function stock(string $variationId): int
    {
        return app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
    }

    public function test_a_single_line_full_return_writes_one_transaction_one_refund_line_and_restocks(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 5);
        $this->setStock($variationId, 10);

        $staffId = $this->staffId();

        $result = DB::transaction(fn () => $this->recorder()->record(
            lines: [['originatingLine' => $origin, 'quantityReturned' => 5, 'restock' => true]],
            clientId: $clientId,
            occurredAt: $this->now(),
            returnedBy: $staffId,
            returnedByName: 'Ana Petrova',
            reason: 'wrong size',
        ));

        $this->assertSame(Channel::WEB, $result->channel());
        $this->assertNotNull($result->id());

        $lines = $result->saleLines();
        $this->assertCount(1, $lines);
        $this->assertNotNull($lines[0]->id());
        $this->assertSame($origin->id(), $lines[0]->originatingSaleLineId());
        $this->assertSame(5, $lines[0]->quantity(), 'quantity is the ORIGIN\'s own quantity');
        $this->assertSame(5, $lines[0]->quantityReturned());
        $this->assertTrue($lines[0]->amount()->equals($this->money(5000)), 'a full return refunds exactly netPaidAmount');
        $this->assertSame($staffId, $lines[0]->returnedBy());
        $this->assertSame('Ana Petrova', $lines[0]->returnedByName());
        $this->assertSame('wrong size', $lines[0]->returnReason());

        $this->assertSame(15, $this->stock($variationId), '10 + 5 returned');
    }

    public function test_restock_false_writes_the_refund_line_but_calls_no_stock_increase(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 5);
        $this->setStock($variationId, 10);

        DB::transaction(fn () => $this->recorder()->record(
            lines: [['originatingLine' => $origin, 'quantityReturned' => 5, 'restock' => false]],
            clientId: $clientId,
            occurredAt: $this->now(),
            returnedBy: null,
            returnedByName: null,
            reason: 'defective, do not restock',
        ));

        $this->assertSame(10, $this->stock($variationId), 'unchanged — restock=false calls no stock increase at all');
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), 'the REFUND line is still written');
    }

    public function test_a_multi_line_return_in_one_call_writes_all_lines_atomically(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $originA = $this->saleLine($clientId, $variationA, quantity: 3);
        $originB = $this->saleLine($clientId, $variationB, quantity: 2);
        $this->setStock($variationA, 10);
        $this->setStock($variationB, 10);

        $result = DB::transaction(fn () => $this->recorder()->record(
            lines: [
                ['originatingLine' => $originA, 'quantityReturned' => 1, 'restock' => true],
                ['originatingLine' => $originB, 'quantityReturned' => 2, 'restock' => true],
            ],
            clientId: $clientId,
            occurredAt: $this->now(),
            returnedBy: null,
            returnedByName: null,
            reason: null,
        ));

        $this->assertCount(2, $result->saleLines());
        $this->assertSame(11, $this->stock($variationA));
        $this->assertSame(12, $this->stock($variationB));
    }

    /**
     * Constructed with a PRIOR REFUND line already present via D5's
     * repository target directly (savePriorRefund()), not by calling the
     * recorder a second time — exactly as instructed.
     */
    public function test_a_return_exceeding_what_remains_is_refused_before_anything_is_written(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 5);
        $this->savePriorRefund($origin, 3); // 3 of 5 already returned — 2 remain
        $this->setStock($variationId, 10);

        $refundLinesBefore = DB::table('operational_sales_sale_lines')->where('type', 'refund')->count();

        try {
            DB::transaction(fn () => $this->recorder()->record(
                lines: [['originatingLine' => $origin, 'quantityReturned' => 3, 'restock' => true]], // only 2 remain
                clientId: $clientId,
                occurredAt: $this->now(),
                returnedBy: null,
                returnedByName: null,
                reason: null,
            ));
            $this->fail('a return exceeding what remains must be refused.');
        } catch (ReturnExceedsRemainingQuantityException $exception) {
            $this->assertSame($origin->id(), $exception->originatingSaleLineId());
            $this->assertSame(3, $exception->requestedQuantity());
            $this->assertSame(2, $exception->remainingQuantity());
        }

        $this->assertSame($refundLinesBefore, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), 'no new REFUND row');
        $this->assertSame(10, $this->stock($variationId), 'no stock change');
    }

    /**
     * The SAME check, but proving it refuses BEFORE the multi-line
     * transaction's own Transaction/SaleLines are written at all — a bad
     * SECOND line must abort the whole call, not just its own line.
     */
    public function test_a_bad_line_in_a_multi_line_return_aborts_the_whole_call(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $originA = $this->saleLine($clientId, $variationA, quantity: 3);
        $originB = $this->saleLine($clientId, $variationB, quantity: 2);
        $this->savePriorRefund($originB, 2); // originB fully returned already — 0 remain
        $this->setStock($variationA, 10);
        $this->setStock($variationB, 10);

        $refundLinesBefore = DB::table('operational_sales_sale_lines')->where('type', 'refund')->count();

        try {
            DB::transaction(fn () => $this->recorder()->record(
                lines: [
                    ['originatingLine' => $originA, 'quantityReturned' => 1, 'restock' => true], // valid on its own
                    ['originatingLine' => $originB, 'quantityReturned' => 1, 'restock' => true], // 0 remain — refused
                ],
                clientId: $clientId,
                occurredAt: $this->now(),
                returnedBy: null,
                returnedByName: null,
                reason: null,
            ));
            $this->fail('the second, bad line must abort the whole call.');
        } catch (ReturnExceedsRemainingQuantityException) {
            // expected
        }

        $this->assertSame($refundLinesBefore, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), 'not even originA\'s own valid line was written');
        $this->assertSame(10, $this->stock($variationA), 'no stock change for the line that WAS individually valid');
    }

    public function test_a_null_net_paid_amount_on_the_origin_refuses_loudly_rather_than_defaulting_to_zero(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();

        // A legacy-shaped line: createNonSale() refuses SALE outright, so
        // simulate a legacy row the honest way — reconstituteFromStorage()
        // with netPaidAmount left at its null default, exactly what a
        // pre-§3.13 row looks like.
        $legacy = SaleLine::reconstituteFromStorage(
            id: 'legacy-line-1',
            transactionId: 'legacy-txn-1',
            clientId: $clientId,
            priceableId: $variationId,
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 2,
            amount: $this->money(2000),
            profit: $this->money(400),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            // netPaidAmount left NULL — a genuine legacy row.
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no netPaidAmount recorded');

        DB::transaction(fn () => $this->recorder()->record(
            lines: [['originatingLine' => $legacy, 'quantityReturned' => 1, 'restock' => true]],
            clientId: $clientId,
            occurredAt: $this->now(),
            returnedBy: null,
            returnedByName: null,
            reason: null,
        ));
    }

    public function test_an_empty_lines_array_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        DB::transaction(fn () => $this->recorder()->record(
            lines: [],
            clientId: $this->clientId(),
            occurredAt: $this->now(),
            returnedBy: null,
            returnedByName: null,
            reason: null,
        ));
    }

    public function test_a_line_belonging_to_a_different_client_is_refused(): void
    {
        $clientId = $this->clientId();
        $otherClientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($otherClientId, $variationId, quantity: 5);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not the client this return is being recorded for');

        DB::transaction(fn () => $this->recorder()->record(
            lines: [['originatingLine' => $origin, 'quantityReturned' => 1, 'restock' => true]],
            clientId: $clientId,
            occurredAt: $this->now(),
            returnedBy: null,
            returnedByName: null,
            reason: null,
        ));
    }
}
