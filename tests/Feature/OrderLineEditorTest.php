<?php

namespace Tests\Feature;

use App\Services\OrderLineEditor;
use App\Services\OrderLineEditResult;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
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
use Throwable;

/**
 * order-editing-design.md §4/§5/§6/§7, stage 3a — App\Services\
 * OrderLineEditor::apply() against a REAL database. Each call here wraps the
 * editor in the TEST's OWN DB::transaction(), simulating the future caller
 * (stage 3b's OrderEditor) that holds the order's own row lock around this
 * call — the editor assumes it is already inside one and never opens its own
 * (see its own docblock), exactly as ReturnGoodsRecorderTest already does for
 * the recorder.
 *
 * WHY THIS SUITE READS RAW COLUMNS RATHER THAN ONLY AGGREGATES: the two
 * central claims of stage 3a are claims about what lands in the ledger —
 * §4.1's deliberate reuse of the REFUND column set for an EDIT_REVERSAL (no
 * new columns, no migration) and §4.2's "nothing is ever rewritten in place".
 * Both are only checkable against the persisted row: `db_table()` reads below
 * assert the exact column each value went in, and the DB::listen() assertion
 * in test_the_origin_rows_are_never_updated_or_deleted proves append-only
 * behaviour from the SQL itself rather than from the resulting state.
 *
 * `stock_levels.variation_id` is a real integer FK (not a plain string —
 * SaleLine.priceableId is untyped, but Inventory's own table genuinely
 * references Catalog), so every fixture line here targets a REAL, freshly
 * created Catalog Product's Universal variation, the same fixture shape
 * ReturnGoodsRecorderTest and CartAfterCheckoutTest already use.
 */
class OrderLineEditorTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function editor(): OrderLineEditor
    {
        return app(OrderLineEditor::class);
    }

    private function money(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, 'EUR');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-30 10:00:00');
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

        $staff = Staff::create('editor.operator@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        return $staff->id();
    }

    /** A real, persisted Catalog Product's Universal variation id — the same fixture shape ReturnGoodsRecorderTest already uses. */
    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "order-line-editor-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    /**
     * Runs one edit the way its real caller will (order-editing-design.md
     * §5): inside the TEST's own DB::transaction(), which is also what makes
     * the "nothing was written" assertions in the refusal tests meaningful —
     * a refusal that had already moved stock rolls that move back with this
     * transaction, exactly as it will with the caller's own.
     *
     * @param  array<int, SaleLine>  $currentLines
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function apply(
        array $currentLines,
        array $changes,
        string $clientId,
        ?string $editedBy = null,
        ?string $editedByName = null,
        ?string $reason = null,
    ): OrderLineEditResult {
        return DB::transaction(fn (): OrderLineEditResult => $this->editor()->apply(
            currentLines: $currentLines,
            changes: $changes,
            clientId: $clientId,
            occurredAt: $this->now(),
            editedBy: $editedBy,
            editedByName: $editedByName,
            reason: $reason,
        ));
    }

    /**
     * A real, persisted SALE line, §3.13-complete — i.e. a line the editor
     * may legitimately reverse and replace. Defaults to 10.00 per unit with
     * no discounts; every argument a test varies is named and the rest stays
     * at the shape the checkout's own SaleLineSnapshotBuilder produces.
     *
     * @param  array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>  $soldAttributes
     */
    private function saleLine(
        string $clientId,
        string $variationId,
        int $quantity = 1,
        int $unitPrice = 1000,
        int $promotionDiscountShare = 0,
        int $discretionaryDiscount = 0,
        ?int $unitCost = null,
        array $soldAttributes = [],
        ?string $originatingReservationLineId = null,
    ): SaleLine {
        $amount = $unitPrice * $quantity;
        $netPaidAmount = $amount - $promotionDiscountShare - $discretionaryDiscount;

        $line = SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: $quantity,
            amount: $this->money($amount),
            profit: $this->money($netPaidAmount - ($unitCost ?? 0) * $quantity),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: $this->money($unitPrice),
            finalUnitPrice: $this->money($unitPrice),
            promotionDiscountShare: $this->money($promotionDiscountShare),
            discretionaryDiscount: $this->money($discretionaryDiscount),
            netPaidAmount: $this->money($netPaidAmount),
            soldAttributes: $soldAttributes,
            unitCost: $unitCost === null ? null : $this->money($unitCost),
            originatingReservationLineId: $originatingReservationLineId,
        );

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine($line);
        app(TransactionRepository::class)->save($transaction);

        return $transaction->saleLines()[0];
    }

    /** A persisted RESERVATION line — the provenance a settled SALE line points at. */
    private function reservationLine(string $clientId, string $variationId, int $quantity): SaleLine
    {
        $line = SaleLine::createNonSale(
            type: SaleLineType::RESERVATION,
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::PENDING,
            quantity: $quantity,
            amount: $this->money(1000 * $quantity),
            profit: $this->money(0),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine($line);
        app(TransactionRepository::class)->save($transaction);

        return $transaction->saleLines()[0];
    }

    /**
     * The exact array shape an "add" change's own "pricedLine" carries — the
     * same nine keys SaleLineSnapshotBuilder::buildForCart() documents, which
     * is also the exact key set OrderLineEditor::validatePricedLine() refuses
     * anything else for.
     *
     * @return array<string, mixed>
     */
    private function pricedLine(
        string $variationId,
        int $quantity,
        int $unitPrice = 1000,
        int $promotionDiscountShare = 0,
        int $discretionaryDiscount = 0,
        ?int $unitCost = null,
        string $productName = 'Added Product',
        string $sku = 'SKU-ADD',
    ): array {
        return [
            'variationId' => $variationId,
            'quantity' => $quantity,
            'regularUnitPrice' => $this->money($unitPrice),
            'finalUnitPrice' => $this->money($unitPrice),
            'unitCost' => $unitCost === null ? null : $this->money($unitCost),
            'productName' => $productName,
            'sku' => $sku,
            'promotionDiscountShare' => $this->money($promotionDiscountShare),
            'discretionaryDiscount' => $this->money($discretionaryDiscount),
        ];
    }

    /**
     * A COPY of $origin read as an OLDER revision — same id, but the quantity
     * and amount it carried before a later change. The editor pins every
     * change against the numbers it would reverse, so a stale copy must be
     * refused rather than reversed at the wrong amount.
     */
    private function staleCopyOf(SaleLine $origin, int $quantityBefore): SaleLine
    {
        return SaleLine::reconstituteFromStorage(
            id: $origin->id(),
            transactionId: $origin->transactionId(),
            clientId: $origin->clientId(),
            priceableId: $origin->priceableId(),
            type: $origin->type(),
            status: $origin->status(),
            quantity: $quantityBefore,
            amount: $this->money(1000 * $quantityBefore),
            profit: $this->money(0),
            recordedAt: $origin->recordedAt(),
            effectiveAt: $origin->effectiveAt(),
            productName: $origin->productName(),
            sku: $origin->sku(),
            regularUnitPrice: $origin->regularUnitPrice(),
            finalUnitPrice: $origin->finalUnitPrice(),
            promotionDiscountShare: $origin->promotionDiscountShare(),
            discretionaryDiscount: $origin->discretionaryDiscount(),
            netPaidAmount: $origin->netPaidAmount(),
            soldAttributes: $origin->soldAttributes(),
            unitCost: $origin->unitCost(),
        );
    }

    /**
     * The sale-line rows ONE edit wrote — the single new Transaction's own
     * lines, ordered the way they were inserted, which is the order §4.2's
     * ledger is defined in.
     *
     * @return array<int, object>
     */
    private function writtenRows(OrderLineEditResult $result): array
    {
        return DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $result->transaction()->id())
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function rowById(string $id): object
    {
        return DB::table('operational_sales_sale_lines')->where('id', $id)->first();
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function stock(string $variationId): int
    {
        return app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
    }

    /**
     * Runs $callback and keeps every statement it issued against $table —
     * this codebase's own DB::listen() counting pattern (see
     * SaleLineSnapshotBuilderQueryCountTest), widened to keep the SQL text so
     * a test can assert both HOW MANY calls an edit made and WHICH rows it
     * touched.
     *
     * @return array{0: mixed, 1: array<int, array{sql: string, bindings: array<int, mixed>}>} The callback's own value, then the statements it issued against $table.
     */
    private function recordStatementsAgainst(string $table, callable $callback): array
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements, $table): void {
            if (str_contains($query->sql, $table)) {
                $statements[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $value = $callback();

        DB::flushQueryLog();

        return [$value, $statements];
    }

    /**
     * @param  array<int, array{sql: string, bindings: array<int, mixed>}>  $statements
     * @return array<int, array{sql: string, bindings: array<int, mixed>}>
     */
    private function statementsStartingWith(string $verb, array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (array $statement): bool => str_starts_with(strtolower(ltrim($statement['sql'])), $verb),
        ));
    }

    /**
     * Runs an edit that must be refused, then proves the refusal left the
     * database exactly as it was: the same row counts and the same stock.
     * Mirrors ReturnGoodsRecorderTest's own "refused before anything is
     * written" assertions.
     */
    private function assertRefusedWithNothingWritten(
        string $variationId,
        int $expectedStock,
        string $messageFragment,
        callable $callback,
        string $expectedException = InvalidArgumentException::class,
    ): void {
        $linesBefore = DB::table('operational_sales_sale_lines')->count();
        $transactionsBefore = DB::table('operational_sales_transactions')->count();

        try {
            $callback();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expectedException, $exception);
            $this->assertStringContainsString($messageFragment, $exception->getMessage());

            $this->assertSame($linesBefore, DB::table('operational_sales_sale_lines')->count(), 'no line written');
            $this->assertSame($transactionsBefore, DB::table('operational_sales_transactions')->count(), 'no Transaction written');
            $this->assertSame($expectedStock, $this->stock($variationId), 'no stock change');

            return;
        }

        $this->fail("An edit that must be refused was accepted (expected: {$messageFragment}).");
    }

    public function test_change_quantity_writes_one_reversal_then_one_replacement_in_the_one_edit_transaction(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();

        // 3 x 10.00 with a 2.00 promotion share, a 1.00 manual discount and a
        // 4.00 unit cost: amount 30.00, netPaidAmount 27.00.
        $soldAttributes = [
            ['definitionId' => '1', 'definitionCode' => 'size', 'definitionName' => 'Size', 'valueId' => '10', 'value' => 'L'],
            ['definitionId' => '2', 'definitionCode' => 'color', 'definitionName' => 'Color', 'valueId' => '20', 'value' => 'Black'],
        ];

        $origin = $this->saleLine(
            clientId: $clientId,
            variationId: $variationId,
            quantity: 3,
            promotionDiscountShare: 200,
            discretionaryDiscount: 100,
            unitCost: 400,
            soldAttributes: $soldAttributes,
        );

        $this->setStock($variationId, 10);

        $transactionsBefore = DB::table('operational_sales_transactions')->count();

        $result = $this->apply(
            currentLines: [$origin],
            changes: [['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 2]],
            clientId: $clientId,
        );

        $this->assertSame($transactionsBefore + 1, DB::table('operational_sales_transactions')->count(), 'one edit = one Transaction');
        $this->assertCount(2, $result->transaction()->saleLines());
        $this->assertSame(
            Channel::WEB->value,
            DB::table('operational_sales_transactions')->where('id', $result->transaction()->id())->value('channel'),
        );

        $rows = $this->writtenRows($result);
        $this->assertCount(2, $rows, 'one reversal + one replacement, and nothing else');
        $this->assertSame($result->transaction()->id(), (string) $rows[0]->transaction_id);
        $this->assertSame($result->transaction()->id(), (string) $rows[1]->transaction_id);

        $reversal = $rows[0];
        $this->assertSame(SaleLineType::EDIT_REVERSAL->value, $reversal->type);
        $this->assertSame(SaleLineStatus::COMPLETED->value, $reversal->status);
        $this->assertSame($origin->id(), (string) $reversal->originating_sale_line_id);
        $this->assertNull($reversal->originating_reservation_line_id);
        $this->assertSame($clientId, (string) $reversal->client_id);
        $this->assertSame($variationId, (string) $reversal->priceable_id);
        $this->assertSame(3, (int) $reversal->quantity, "the reversal is the origin's own whole quantity");
        $this->assertSame(3, (int) $reversal->quantity_returned);
        $this->assertSame(2700, (int) $reversal->amount_minor, "§4.3: a reversal records the origin's own netPaidAmount, not its amount (30.00)");
        $this->assertSame(2700, (int) $reversal->default_refund_amount_minor);
        $this->assertSame(2700, (int) $reversal->actual_refund_amount_minor);
        $this->assertSame(0, (int) $reversal->profit_minor);
        $this->assertNull($reversal->display_price_at_return_minor, 'no display price is passed to a reversal');
        $this->assertNull($reversal->net_paid_amount_minor, 'a reversal carries none of the §3.13 snapshot');
        $this->assertNull($reversal->unit_cost_minor);
        $this->assertNull($reversal->product_name);
        $this->assertNull($reversal->sold_attributes);
        $this->assertSame('2026-09-30 10:00:00', (string) $reversal->recorded_at);
        $this->assertSame('2026-09-30 10:00:00', (string) $reversal->effective_at);

        $replacement = $rows[1];
        $this->assertSame(SaleLineType::SALE->value, $replacement->type);
        $this->assertSame(SaleLineStatus::COMPLETED->value, $replacement->status);
        $this->assertSame($origin->id(), (string) $replacement->originating_sale_line_id, '§4.2: lineage — this line replaced that one');
        $this->assertNull($replacement->originating_reservation_line_id);
        $this->assertSame($clientId, (string) $replacement->client_id);
        $this->assertSame($variationId, (string) $replacement->priceable_id);
        $this->assertSame(2, (int) $replacement->quantity);
        $this->assertSame(1000, (int) $replacement->regular_unit_price_minor);
        $this->assertSame(1000, (int) $replacement->final_unit_price_minor);
        $this->assertSame(2000, (int) $replacement->amount_minor);
        $this->assertSame(200, (int) $replacement->promotion_discount_share_minor, "the origin's own share carries forward when the change omits one");
        $this->assertSame(100, (int) $replacement->discretionary_discount_minor);
        $this->assertSame(1700, (int) $replacement->net_paid_amount_minor);
        $this->assertSame(400, (int) $replacement->unit_cost_minor);

        $this->assertSame(900, (int) $replacement->profit_minor, '§4.6: profit is computed fresh from the NEW quantity and the carried cost');
        $this->assertSame('Product One', (string) $replacement->product_name);
        $this->assertSame('SKU-1', (string) $replacement->sku);
        $carriedAttributes = json_decode((string) $replacement->sold_attributes, true);
        $this->assertSame(
            ['size' => 'L', 'color' => 'Black'],
            array_column($carriedAttributes, 'value', 'definitionCode'),
            "the origin's attribute snapshot, carried forward verbatim",
        );
        $this->assertNull($replacement->quantity_returned, 'the replacement is no kind of refund line');

        $resulting = $result->resultingLines();
        $this->assertCount(1, $resulting);
        $this->assertSame((string) $replacement->id, $resulting[0]->id(), "the replacement IS the order's line for that product from now on");
        $this->assertSame(2, $resulting[0]->quantity());

        $this->assertSame(11, $this->stock($variationId), '10 + 3 released - 2 re-ordered');

    }

    public function test_the_reversal_reuses_the_refund_columns_for_the_editing_staff_and_reason(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 1);
        $this->setStock($variationId, 5);

        $staffId = $this->staffId();

        $result = $this->apply(
            currentLines: [$origin],
            changes: [['change' => 'discount', 'originatingLine' => $origin, 'discretionaryDiscount' => $this->money(100)]],
            clientId: $clientId,
            editedBy: $staffId,
            editedByName: 'Ana Petrova',
            reason: 'goodwill discount',
        );

        $reversal = $this->writtenRows($result)[0];

        $this->assertSame($staffId, (string) $reversal->returned_by, '§4.1: the editing staff member goes in the REFUND column set');
        $this->assertSame('Ana Petrova', (string) $reversal->returned_by_name);
        $this->assertSame('goodwill discount', (string) $reversal->return_reason);
        $this->assertSame($this->money(1000)->minorValue(), (int) $reversal->amount_minor, 'reversal of the whole line: netPaidAmount 10.00');
    }

    public function test_a_removed_line_is_reversed_with_no_replacement_and_releases_its_units(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $lineA = $this->saleLine($clientId, $variationA, quantity: 2);
        $lineB = $this->saleLine($clientId, $variationB, quantity: 3);
        $this->setStock($variationA, 5);
        $this->setStock($variationB, 4);

        $result = $this->apply(
            currentLines: [$lineA, $lineB],
            changes: [['change' => 'remove', 'originatingLine' => $lineA]],
            clientId: $clientId,
        );

        $rows = $this->writtenRows($result);
        $this->assertCount(1, $rows, 'a removal is reversed, never replaced');
        $this->assertSame(SaleLineType::EDIT_REVERSAL->value, $rows[0]->type);
        $this->assertSame($lineA->id(), (string) $rows[0]->originating_sale_line_id);
        $this->assertSame(2, (int) $rows[0]->quantity);
        $this->assertSame(2, (int) $rows[0]->quantity_returned);
        $this->assertSame(2000, (int) $rows[0]->amount_minor);

        $resulting = $result->resultingLines();
        $this->assertCount(1, $resulting);
        $this->assertSame($lineB, $resulting[0], 'an untouched line passes through as the very object it was');

        $this->assertSame(7, $this->stock($variationA), '5 + 2 released');
        $this->assertSame(4, $this->stock($variationB), 'a variation this edit never named is untouched');
    }

    public function test_an_added_line_is_a_fresh_sale_line_with_no_originating_line(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $lineA = $this->saleLine($clientId, $variationA, quantity: 1);
        $this->setStock($variationA, 3);
        $this->setStock($variationB, 10);

        $result = $this->apply(
            currentLines: [$lineA],
            changes: [['change' => 'add', 'pricedLine' => $this->pricedLine($variationB, quantity: 2, unitPrice: 1500, unitCost: 500)]],
            clientId: $clientId,
        );

        $rows = $this->writtenRows($result);
        $this->assertCount(1, $rows);
        $added = $rows[0];

        $this->assertSame(SaleLineType::SALE->value, $added->type);
        $this->assertSame(SaleLineStatus::COMPLETED->value, $added->status);
        $this->assertNull($added->originating_sale_line_id, 'an added line replaces nothing');
        $this->assertNull($added->originating_reservation_line_id);
        $this->assertSame($clientId, (string) $added->client_id);
        $this->assertSame($variationB, (string) $added->priceable_id);
        $this->assertSame(2, (int) $added->quantity);
        $this->assertSame(1500, (int) $added->regular_unit_price_minor);
        $this->assertSame(3000, (int) $added->amount_minor);
        $this->assertSame(3000, (int) $added->net_paid_amount_minor);
        $this->assertSame(500, (int) $added->unit_cost_minor);
        $this->assertSame(2000, (int) $added->profit_minor, '30.00 net - 2 x 5.00 cost, computed by SaleLineSnapshotBuilder');
        $this->assertSame('Added Product', (string) $added->product_name);
        $this->assertSame('SKU-ADD', (string) $added->sku);
        $this->assertSame([], json_decode((string) $added->sold_attributes, true), 'a simple product resolves to an empty attribute list');
        $this->assertSame('2026-09-30 10:00:00', (string) $added->effective_at);

        $resulting = $result->resultingLines();
        $this->assertCount(2, $resulting);
        $this->assertSame($lineA, $resulting[0]);
        $this->assertSame((string) $added->id, $resulting[1]->id(), 'added lines are appended');

        $this->assertSame(3, $this->stock($variationA), 'the existing line did not move');
        $this->assertSame(8, $this->stock($variationB), '10 - 2 added');
    }

    public function test_the_ledger_is_written_reversals_first_then_replacements_then_added_lines(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $variationC = $this->variationId();
        $lineA = $this->saleLine($clientId, $variationA, quantity: 1);
        $lineB = $this->saleLine($clientId, $variationB, quantity: 2);
        $this->setStock($variationA, 5);
        $this->setStock($variationB, 5);
        $this->setStock($variationC, 5);

        // The operator's own form listed them in this order: change B, remove
        // A, add C. §4.2's ledger is written in that order *within* each role.
        $result = $this->apply(
            currentLines: [$lineA, $lineB],
            changes: [
                ['change' => 'change_quantity', 'originatingLine' => $lineB, 'quantity' => 3],
                ['change' => 'remove', 'originatingLine' => $lineA],
                ['change' => 'add', 'pricedLine' => $this->pricedLine($variationC, quantity: 1)],
            ],
            clientId: $clientId,
        );

        $rows = $this->writtenRows($result);
        $this->assertCount(4, $rows, 'two reversals + one replacement + one added line');

        // Both reversals first, in the order the changes named their lines...
        $this->assertSame(SaleLineType::EDIT_REVERSAL->value, $rows[0]->type);
        $this->assertSame($lineB->id(), (string) $rows[0]->originating_sale_line_id);
        $this->assertSame(SaleLineType::EDIT_REVERSAL->value, $rows[1]->type);
        $this->assertSame($lineA->id(), (string) $rows[1]->originating_sale_line_id);

        // ...then every replacement (only B has one: A was removed)...
        $this->assertSame(SaleLineType::SALE->value, $rows[2]->type);
        $this->assertSame($lineB->id(), (string) $rows[2]->originating_sale_line_id);
        $this->assertSame(3, (int) $rows[2]->quantity);

        // ...then the added line, which replaces nothing.
        $this->assertSame(SaleLineType::SALE->value, $rows[3]->type);
        $this->assertNull($rows[3]->originating_sale_line_id);
        $this->assertSame($variationC, (string) $rows[3]->priceable_id);

        // §4.4: the caller's own display order survives, a removal is gone, a
        // replacement sits where its origin sat, an addition is appended.
        $resulting = $result->resultingLines();
        $this->assertCount(2, $resulting);
        $this->assertSame((string) $rows[2]->id, $resulting[0]->id());
        $this->assertSame((string) $rows[3]->id, $resulting[1]->id());

        $this->assertSame(6, $this->stock($variationA), '5 + 1 released by the removal');
        $this->assertSame(4, $this->stock($variationB), '5 + 2 released - 3 re-ordered');
        $this->assertSame(4, $this->stock($variationC), '5 - 1 added');
    }

    public function test_a_replacement_does_not_re_claim_the_origins_reservation_provenance(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $reservation = $this->reservationLine($clientId, $variationId, 2);
        $origin = $this->saleLine($clientId, $variationId, quantity: 2, originatingReservationLineId: $reservation->id());
        $this->setStock($variationId, 1);

        $result = $this->apply(
            currentLines: [$origin],
            changes: [['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 1]],
            clientId: $clientId,
        );

        $rows = $this->writtenRows($result);
        $reversal = $rows[0];
        $replacement = $rows[1];

        // §4.2: one-hop lineage, deliberately. The replacement points at the
        // line it replaced, never at that line's own reservation, and the
        // reversal claims nothing either — otherwise two SALE lines would
        // each read as that one reservation's settlement.
        $this->assertNull($reversal->originating_reservation_line_id);
        $this->assertNull($replacement->originating_reservation_line_id);
        $this->assertSame($origin->id(), (string) $replacement->originating_sale_line_id);

        // The walk origin -> reservation is still there, and the reservation
        // row itself was never touched by an edit.
        $originRow = $this->rowById($origin->id());
        $this->assertSame($reservation->id(), (string) $originRow->originating_reservation_line_id);
        $this->assertSame(2, (int) $originRow->quantity, 'the origin keeps the state it had — only this edit happened');

        $reservationRow = $this->rowById($reservation->id());
        $this->assertSame(SaleLineType::RESERVATION->value, (string) $reservationRow->type);
        $this->assertSame(2, (int) $reservationRow->quantity);
        $this->assertSame($reservation->transactionId(), (string) $reservationRow->transaction_id);
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', SaleLineType::RESERVATION->value)->count());

        $this->assertSame(2, $this->stock($variationId), '1 + 2 released - 1 re-ordered');
    }

    /**
     * §6's own example, made concrete: a change that only LOWERS a quantity
     * is an increase of the difference, so it never needs stock on hand. The
     * editor nets one line's two quantities before it books anything — a
     * 5 -> 3 change is ONE increase of 2, not an increase of 5 followed by a
     * decrease of 3 (which is why the docblock on the stock phase spells the
     * rule out as "increases, then decreases", and not as a claim that both
     * halves of a single line's change become separate calls).
     */
    public function test_a_change_that_only_lowers_a_quantity_needs_no_stock_on_hand(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 5);
        $this->setStock($variationId, 0);

        $result = $this->apply(
            currentLines: [$origin],
            changes: [['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 3]],
            clientId: $clientId,
        );

        $this->assertSame(2, $this->stock($variationId), '0 + the 2 units this change gave back');
        $this->assertSame(3, $result->resultingLines()[0]->quantity());
    }

    /**
     * The one shape that genuinely needs §6's ordering rule: a variation this
     * edit both releases units TO (the removed line) and takes units FROM
     * (the added line). With nothing on hand the +3 has to land before the
     * -2, or this order could never be edited at all.
     */
    public function test_increases_run_before_decreases_on_a_variation_touched_both_ways(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 3);
        $this->setStock($variationId, 0);

        $result = $this->apply(
            currentLines: [$origin],
            changes: [
                ['change' => 'remove', 'originatingLine' => $origin],
                ['change' => 'add', 'pricedLine' => $this->pricedLine($variationId, quantity: 2)],
            ],
            clientId: $clientId,
        );

        $this->assertSame(1, $this->stock($variationId), '0 + 3 released - 2 re-ordered');
        $this->assertCount(1, $result->resultingLines());
        $this->assertSame(2, $result->resultingLines()[0]->quantity());
    }

    public function test_stock_is_aggregated_per_variation_and_per_direction_not_per_line(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $variationC = $this->variationId();
        $lineA1 = $this->saleLine($clientId, $variationA, quantity: 2);
        $lineA2 = $this->saleLine($clientId, $variationA, quantity: 3);
        $lineB = $this->saleLine($clientId, $variationB, quantity: 1);
        $lineC = $this->saleLine($clientId, $variationC, quantity: 1);
        $this->setStock($variationA, 0);
        $this->setStock($variationB, 0);
        $this->setStock($variationC, 5);

        [$result, $statements] = $this->recordStatementsAgainst(
            'stock_levels',
            fn (): OrderLineEditResult => $this->apply(
                currentLines: [$lineA1, $lineA2, $lineB, $lineC],
                changes: [
                    ['change' => 'remove', 'originatingLine' => $lineA1],
                    ['change' => 'remove', 'originatingLine' => $lineA2],
                    ['change' => 'remove', 'originatingLine' => $lineB],
                    ['change' => 'add', 'pricedLine' => $this->pricedLine($variationC, quantity: 2)],
                ],
                clientId: $clientId,
            ),
        );

        $updates = $this->statementsStartingWith('update', $statements);

        // Four lines named, three variations touched, two directions: varA's
        // two removals are ONE +5 call and varC's added pair is ONE -2 call.
        // A per-line implementation would have issued four.
        $this->assertCount(3, $updates, 'one call per variation per direction');

        // Which variation each call is about — read off its own bound
        // parameters, since the amounts themselves are inlined into the
        // UPDATE's SET expression. The ORDER is the assertion that matters
        // here: varA/varB can only be increases (each line's quantity went
        // down to nothing) and varC can only be a decrease (nothing of varC
        // was released), so increase-before-decrease is visible in the order
        // the statements were issued.
        $bindsVariation = static fn (array $statement, string $variationId): bool => in_array(
            $variationId,
            array_map('strval', $statement['bindings']),
            true,
        );

        $this->assertTrue($bindsVariation($updates[0], $variationA), 'the varA increase runs first');
        $this->assertTrue($bindsVariation($updates[1], $variationB), 'then the varB increase');
        $this->assertTrue($bindsVariation($updates[2], $variationC), 'and only then the varC decrease');

        $this->assertSame(5, $this->stock($variationA), '0 + 2 + 3 released');
        $this->assertSame(1, $this->stock($variationB), '0 + 1 released');
        $this->assertSame(3, $this->stock($variationC), '5 - 2 added');

        $resulting = $result->resultingLines();
        $this->assertCount(2, $resulting);
        $this->assertSame($lineC, $resulting[0], 'the one untouched line keeps its place');
        $this->assertSame(2, $resulting[1]->quantity());
    }

    /**
     * §4.2's append-only rule, proved from the database's side rather than
     * from the returned aggregate: the edit is a pure INSERT stream. An
     * operator can still read how the order got to where it is, and no
     * already-issued line (a receipt, a refund, a ledger export) is
     * rewritten out from under whoever already read it.
     */
    public function test_the_origin_rows_are_never_updated_or_deleted(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $origin = $this->saleLine($clientId, $variationA, quantity: 2);
        $removed = $this->saleLine($clientId, $variationB, quantity: 1);
        $this->setStock($variationA, 4);
        $this->setStock($variationB, 4);

        $originRowBefore = (array) $this->rowById($origin->id());
        $removedRowBefore = (array) $this->rowById($removed->id());

        [, $statements] = $this->recordStatementsAgainst(
            'operational_sales_sale_lines',
            fn (): OrderLineEditResult => $this->apply(
                currentLines: [$origin, $removed],
                changes: [
                    ['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 1],
                    ['change' => 'remove', 'originatingLine' => $removed],
                ],
                clientId: $clientId,
            ),
        );

        $this->assertSame([], $this->statementsStartingWith('update', $statements), 'no line row was ever rewritten');
        $this->assertSame([], $this->statementsStartingWith('delete', $statements), 'no line row was ever deleted');
        $this->assertCount(
            3,
            $this->statementsStartingWith('insert', $statements),
            'three appended rows: two reversals and one replacement',
        );

        $this->assertSame($originRowBefore, (array) $this->rowById($origin->id()), 'the origin row is byte-for-byte what it was');
        $this->assertSame($removedRowBefore, (array) $this->rowById($removed->id()));
        $this->assertSame(5, DB::table('operational_sales_sale_lines')->count(), 'two fixture lines + three appended');
    }

    public function test_a_discount_only_edit_moves_no_stock_at_all(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 1);
        $this->setStock($variationId, 7);

        [$result, $statements] = $this->recordStatementsAgainst(
            'stock_levels',
            fn (): OrderLineEditResult => $this->apply(
                currentLines: [$origin],
                changes: [['change' => 'discount', 'originatingLine' => $origin, 'discretionaryDiscount' => $this->money(100)]],
                clientId: $clientId,
            ),
        );

        // The line was still reversed and re-issued — that is how a new
        // discount becomes part of the history — but qty in = qty out, so
        // nothing is asked of the stock ledger and the per-direction
        // aggregation has nothing to send.
        $this->assertSame([], $this->statementsStartingWith('update', $statements), 'no stock UPDATE at all');
        $this->assertSame([], $this->statementsStartingWith('insert', $statements), 'no stock row created either');
        $this->assertSame(7, $this->stock($variationId));

        $this->assertCount(2, $result->transaction()->saleLines(), 'one reversal + one replacement');
        $this->assertSame(1, $result->resultingLines()[0]->quantity(), 'the quantity rode along untouched');
        $this->assertSame(100, $result->resultingLines()[0]->discretionaryDiscount()->minorValue());
    }

    public function test_an_edit_that_would_oversell_is_refused_and_releases_nothing(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 3);
        // 3 of the 5 asked for can be served (3 released first), then the
        // decrease of 5 finds only 4 on hand and the whole attempt dies.
        $this->setStock($variationId, 1);

        $this->assertRefusedWithNothingWritten(
            $variationId,
            1,
            'insufficient quantity available',
            fn () => $this->apply(
                currentLines: [$origin],
                changes: [['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 5]],
                clientId: $clientId,
            ),
            InsufficientStockException::class,
        );
    }

    /**
     * §4.2's write phase is reversed and re-issued rather than rewritten, so
     * every change carries a copy of the line the form was RENDERED from. If
     * that copy is older than what the order actually holds, the reversal
     * would be built from the wrong numbers and persisted as truthfully as a
     * right one — refused instead, before anything moves.
     */
    public function test_a_change_carrying_a_stale_revision_of_its_line_is_refused(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 5);
        $this->setStock($variationId, 3);

        // Same id, yesterday's quantity — a second tab, or a form opened
        // before someone else's edit landed.
        $stale = $this->staleCopyOf($origin, quantityBefore: 4);

        $this->assertRefusedWithNothingWritten(
            $variationId,
            3,
            'carries a stale revision of line',
            fn () => $this->apply(
                currentLines: [$origin],
                changes: [['change' => 'change_quantity', 'originatingLine' => $stale, 'quantity' => 2]],
                clientId: $clientId,
            ),
        );
    }

    public function test_a_change_naming_a_line_outside_the_current_lines_is_refused(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $current = $this->saleLine($clientId, $variationId, quantity: 2);
        // A line that is already gone — fully reversed by an earlier edit.
        $ghost = $this->saleLine($clientId, $variationId, quantity: 2);
        $this->setStock($variationId, 6);

        $this->assertRefusedWithNothingWritten(
            $variationId,
            6,
            'which is not one of the current lines',
            fn () => $this->apply(
                currentLines: [$current],
                changes: [['change' => 'remove', 'originatingLine' => $ghost]],
                clientId: $clientId,
            ),
        );
    }

    public function test_a_line_named_with_two_contradictory_changes_is_refused(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 2);
        $this->setStock($variationId, 6);

        // "Remove it — and also give it a discount": the second instruction
        // describes a replacement for a line the first one just deleted.
        $this->assertRefusedWithNothingWritten(
            $variationId,
            6,
            'is named by both a',
            fn () => $this->apply(
                currentLines: [$origin],
                changes: [
                    ['change' => 'remove', 'originatingLine' => $origin],
                    ['change' => 'discount', 'originatingLine' => $origin, 'discretionaryDiscount' => $this->money(100)],
                ],
                clientId: $clientId,
            ),
        );
    }

    public function test_a_quantity_of_zero_is_refused_instead_of_silently_meaning_remove(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 2);
        $this->setStock($variationId, 6);

        $this->assertRefusedWithNothingWritten(
            $variationId,
            6,
            'must be an integer of at least 1',
            fn () => $this->apply(
                currentLines: [$origin],
                changes: [['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 0]],
                clientId: $clientId,
            ),
        );
    }

    public function test_a_discount_larger_than_the_lines_own_regular_price_is_refused(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $origin = $this->saleLine($clientId, $variationId, quantity: 1);
        $this->setStock($variationId, 6);

        // 1 x 10.00 regular, so a 20.00 manual discount is a data error, not
        // a negative-priced line: the money would be invented, not recorded.
        $this->assertRefusedWithNothingWritten(
            $variationId,
            6,
            "a manual discount larger than the line's own regular price",
            fn () => $this->apply(
                currentLines: [$origin],
                changes: [['change' => 'discount', 'originatingLine' => $origin, 'discretionaryDiscount' => $this->money(2000)]],
                clientId: $clientId,
            ),
        );
    }

    public function test_an_edit_that_removes_every_line_is_refused(): void
    {
        $clientId = $this->clientId();
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $lineA = $this->saleLine($clientId, $variationA, quantity: 1);
        $lineB = $this->saleLine($clientId, $variationB, quantity: 1);
        $this->setStock($variationA, 6);
        $this->setStock($variationB, 6);

        // An order with no lines has no price and no lifecycle — cancelling
        // is a different operation with its own ledger entry.
        $this->assertRefusedWithNothingWritten(
            $variationA,
            6,
            'this edit removes every line of the order',
            fn () => $this->apply(
                currentLines: [$lineA, $lineB],
                changes: [
                    ['change' => 'remove', 'originatingLine' => $lineA],
                    ['change' => 'remove', 'originatingLine' => $lineB],
                ],
                clientId: $clientId,
            ),
        );

        $this->assertSame(6, $this->stock($variationB), 'not even the second line was released');
    }

    /**
     * The §3.13 precondition on the write side, and the reason an edit never
     * reverses a guessed amount: an EDIT_REVERSAL's amount is DERIVED from
     * the origin's netPaidAmount(), so a pre-§3.13 row has nothing to reverse.
     * Such a line is refunded and re-ordered instead.
     */
    public function test_a_legacy_line_with_no_net_paid_amount_is_refused(): void
    {
        $clientId = $this->clientId();
        $variationId = $this->variationId();
        $this->setStock($variationId, 7);

        // A genuine legacy row: the same fixture shape ReturnGoodsRecorderTest
        // uses — reconstituteFromStorage() with netPaidAmount left at its own
        // null default, which is exactly what a pre-§3.13 row looks like.
        $legacy = SaleLine::reconstituteFromStorage(
            id: 'legacy-line-1',
            transactionId: 'legacy-txn-1',
            clientId: $clientId,
            priceableId: $variationId,
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(1000),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );

        $this->assertRefusedWithNothingWritten(
            $variationId,
            7,
            'it carries no netPaidAmount',
            fn () => $this->apply(
                currentLines: [$legacy],
                changes: [['change' => 'change_quantity', 'originatingLine' => $legacy, 'quantity' => 2]],
                clientId: $clientId,
            ),
        );
    }
}
