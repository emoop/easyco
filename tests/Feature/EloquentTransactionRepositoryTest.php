<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\Persistence\Eloquent\TransactionModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentTransactionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): TransactionRepository
    {
        return app(TransactionRepository::class);
    }

    private function clientId(): string
    {
        return (string) ClientModel::create(['name' => 'Test Client'])->id;
    }

    private function money(int $minorUnits, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minorUnits, $currency);
    }

    public function test_save_then_find_by_id_with_sale_lines_round_trips_a_single_line_correctly(): void
    {
        $clientId = $this->clientId();
        $recordedAt = new DateTimeImmutable('2026-08-25 10:00:00');
        $effectiveAt = new DateTimeImmutable('2026-08-20 09:00:00');

        $transaction = new Transaction(id: null, channel: Channel::POS);
        $saleLine = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 3,
            amount: $this->money(2500),
            profit: $this->money(400),
            recordedAt: $recordedAt,
            effectiveAt: $effectiveAt,
            productName: 'Product One',
            sku: 'SKU-1',
        );
        $transaction->addSaleLine($saleLine);

        $this->repository()->save($transaction);

        $this->assertNotNull($transaction->id());
        $this->assertNotNull($saleLine->id());
        $this->assertSame($transaction->id(), $saleLine->transactionId());

        $reloaded = $this->repository()->findByIdWithSaleLines($transaction->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($transaction->id(), $reloaded->id());
        $this->assertSame(Channel::POS, $reloaded->channel());
        $this->assertCount(1, $reloaded->saleLines());

        $reloadedLine = $reloaded->saleLines()[0];
        $this->assertSame($saleLine->id(), $reloadedLine->id());
        $this->assertSame($transaction->id(), $reloadedLine->transactionId());
        $this->assertSame($clientId, $reloadedLine->clientId());
        $this->assertSame('priceable-1', $reloadedLine->priceableId());
        $this->assertSame(SaleLineType::SALE, $reloadedLine->type());
        $this->assertSame(SaleLineStatus::COMPLETED, $reloadedLine->status());
        $this->assertSame(3, $reloadedLine->quantity());
        $this->assertTrue($reloadedLine->amount()->equals($this->money(2500)));
        $this->assertTrue($reloadedLine->profit()->equals($this->money(400)));
        $this->assertEquals($recordedAt, $reloadedLine->recordedAt());
        $this->assertEquals($effectiveAt, $reloadedLine->effectiveAt());
        $this->assertNull($reloadedLine->originatingSaleLineId());
        $this->assertNull($reloadedLine->originatingReservationLineId());
        $this->assertSame('Product One', $reloadedLine->productName());
        $this->assertSame('SKU-1', $reloadedLine->sku());
    }

    public function test_a_shipping_line_with_null_priceable_id_round_trips_correctly(): void
    {
        $clientId = $this->clientId();

        $transaction = new Transaction(id: null, channel: Channel::WEB);
        $shippingLine = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: null,
            type: SaleLineType::SHIPPING,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(599),
            profit: $this->money(0),
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable(),
        );
        $transaction->addSaleLine($shippingLine);

        $this->repository()->save($transaction);

        $reloaded = $this->repository()->findByIdWithSaleLines($transaction->id());

        $this->assertNull($reloaded->saleLines()[0]->priceableId());
        $this->assertSame(SaleLineType::SHIPPING, $reloaded->saleLines()[0]->type());
    }

    public function test_a_refund_line_preserves_its_originating_sale_line_id_across_a_reload(): void
    {
        $clientId = $this->clientId();

        $transaction = new Transaction(id: null, channel: Channel::POS);
        $originalSale = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(1000),
            profit: $this->money(200),
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable(),
            productName: 'Product One',
            sku: 'SKU-1',
        );
        $transaction->addSaleLine($originalSale);
        $this->repository()->save($transaction);

        $refund = new SaleLine(
            id: null,
            transactionId: $transaction->id(),
            clientId: $clientId,
            priceableId: 'priceable-1',
            type: SaleLineType::REFUND,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(1000),
            profit: $this->money(200),
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable(),
            originatingSaleLineId: $originalSale->id(),
        );
        $transaction->addSaleLine($refund);
        $this->repository()->save($transaction);

        $reloaded = $this->repository()->findByIdWithSaleLines($transaction->id());
        $this->assertCount(2, $reloaded->saleLines());

        $reloadedRefund = array_values(array_filter(
            $reloaded->saleLines(),
            fn (SaleLine $line) => $line->type() === SaleLineType::REFUND
        ))[0];

        $this->assertSame($originalSale->id(), $reloadedRefund->originatingSaleLineId());
    }

    public function test_find_by_id_with_sale_lines_returns_null_for_a_nonexistent_id(): void
    {
        $this->assertNull($this->repository()->findByIdWithSaleLines('999999'));
    }

    /**
     * §3.13 D5/round-trip requirement: EVERY field, built via the new
     * SaleLine::create() (not `new SaleLine(...)`), including
     * soldAttributes' own ORDER — the shared SaleLineMapper must not
     * silently reorder a JSON array on the way in or out.
     */
    public function test_a_fully_populated_snapshot_round_trips_correctly_including_attribute_order(): void
    {
        $clientId = $this->clientId();
        $recordedAt = new DateTimeImmutable('2026-09-24 10:00:00');
        $effectiveAt = new DateTimeImmutable('2026-09-24 10:00:00');
        $soldAttributes = [
            ['definitionId' => '2', 'definitionCode' => 'size', 'definitionName' => 'Size', 'valueId' => '20', 'value' => 'M'],
            ['definitionId' => '1', 'definitionCode' => 'color', 'definitionName' => 'Color', 'valueId' => '10', 'value' => 'Black'],
        ];

        $transaction = new Transaction(id: null, channel: Channel::WEB);
        $saleLine = SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: 'priceable-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 2,
            amount: $this->money(2000),
            profit: $this->money(1000),
            recordedAt: $recordedAt,
            effectiveAt: $effectiveAt,
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: $this->money(1100),
            finalUnitPrice: $this->money(1000),
            promotionDiscountShare: $this->money(100),
            discretionaryDiscount: $this->money(50),
            netPaidAmount: $this->money(1850),
            soldAttributes: $soldAttributes,
            unitCost: $this->money(400),
        );
        $transaction->addSaleLine($saleLine);

        $this->repository()->save($transaction);

        $reloaded = $this->repository()->findByIdWithSaleLines($transaction->id());
        $reloadedLine = $reloaded->saleLines()[0];

        $this->assertTrue($reloadedLine->regularUnitPrice()->equals($this->money(1100)));
        $this->assertTrue($reloadedLine->finalUnitPrice()->equals($this->money(1000)));
        $this->assertTrue($reloadedLine->promotionDiscountShare()->equals($this->money(100)));
        $this->assertTrue($reloadedLine->discretionaryDiscount()->equals($this->money(50)));
        $this->assertTrue($reloadedLine->netPaidAmount()->equals($this->money(1850)));
        $this->assertTrue($reloadedLine->unitCost()->equals($this->money(400)));
        // assertEquals, not assertSame: MySQL's JSON column type does not
        // guarantee preserving each OBJECT's own key insertion order on
        // read-back (confirmed by a real failure here first) — only the
        // outer LIST order (which attribute comes first) is semantically
        // meaningful and actually asserted below; within one attribute
        // entry, keys are always accessed by name, never by position.
        $this->assertEquals($soldAttributes, $reloadedLine->soldAttributes(), 'attribute order must survive the round trip exactly');
        $this->assertSame('size', $reloadedLine->soldAttributes()[0]['definitionCode']);
        $this->assertSame('color', $reloadedLine->soldAttributes()[1]['definitionCode']);
    }

    /**
     * §3.13 E-D5 / §3.12's amendment — the exact Г-found defect fixed by
     * this stage. Written with a raw insert, deliberately bypassing the
     * domain layer entirely, to simulate a row genuinely written before
     * product_name/sku (and now every §3.13 column) existed.
     */
    public function test_a_legacy_row_with_null_snapshot_fields_reads_back_without_throwing(): void
    {
        $clientId = $this->clientId();
        $transactionModel = TransactionModel::create(['channel' => Channel::WEB->value]);

        DB::table('operational_sales_sale_lines')->insert([
            'transaction_id' => $transactionModel->id,
            'client_id' => $clientId,
            'priceable_id' => 'priceable-1',
            'product_name' => null,
            'sku' => null,
            'type' => SaleLineType::SALE->value,
            'status' => SaleLineStatus::COMPLETED->value,
            'quantity' => 1,
            'amount_minor' => 1000,
            'amount_currency' => 'EUR',
            'profit_minor' => 200,
            'profit_currency' => 'EUR',
            'recorded_at' => now(),
            'effective_at' => now(),
            // Every §3.13 column left NULL — the legacy shape.
            'regular_unit_price_minor' => null,
            'regular_unit_price_currency' => null,
            'final_unit_price_minor' => null,
            'final_unit_price_currency' => null,
            'promotion_discount_share_minor' => null,
            'promotion_discount_share_currency' => null,
            'discretionary_discount_minor' => null,
            'discretionary_discount_currency' => null,
            'net_paid_amount_minor' => null,
            'net_paid_amount_currency' => null,
            'unit_cost_minor' => null,
            'unit_cost_currency' => null,
            'sold_attributes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reloaded = $this->repository()->findByIdWithSaleLines($transactionModel->id);

        $this->assertCount(1, $reloaded->saleLines());
        $reloadedLine = $reloaded->saleLines()[0];

        $this->assertNull($reloadedLine->productName());
        $this->assertNull($reloadedLine->sku());
        $this->assertNull($reloadedLine->regularUnitPrice());
        $this->assertNull($reloadedLine->finalUnitPrice());
        $this->assertNull($reloadedLine->promotionDiscountShare());
        $this->assertNull($reloadedLine->discretionaryDiscount());
        $this->assertNull($reloadedLine->netPaidAmount());
        $this->assertNull($reloadedLine->soldAttributes());
        $this->assertNull($reloadedLine->unitCost());
    }

    /**
     * SaleLineMapper::moneyOrNull()'s own corrupt-row guard, reviewed
     * after stage 2's own initial cut silently treated a half-populated
     * minor/currency pair as "unset" — a real bug this row shape would
     * have hidden rather than surfaced. A legacy row (§3.13 E-D5) has
     * NEITHER column of a pair set; ONE set and the other NULL is not a
     * legitimate write path's output at all.
     */
    public function test_a_half_populated_money_pair_throws_instead_of_reading_as_null(): void
    {
        $clientId = $this->clientId();
        $transactionModel = TransactionModel::create(['channel' => Channel::WEB->value]);

        DB::table('operational_sales_sale_lines')->insert([
            'transaction_id' => $transactionModel->id,
            'client_id' => $clientId,
            'priceable_id' => 'priceable-1',
            'product_name' => null,
            'sku' => null,
            'type' => SaleLineType::SALE->value,
            'status' => SaleLineStatus::COMPLETED->value,
            'quantity' => 1,
            'amount_minor' => 1000,
            'amount_currency' => 'EUR',
            'profit_minor' => 200,
            'profit_currency' => 'EUR',
            'recorded_at' => now(),
            'effective_at' => now(),
            // The corrupt pair: minor set, currency NULL.
            'regular_unit_price_minor' => 1100,
            'regular_unit_price_currency' => null,
            'final_unit_price_minor' => null,
            'final_unit_price_currency' => null,
            'promotion_discount_share_minor' => null,
            'promotion_discount_share_currency' => null,
            'discretionary_discount_minor' => null,
            'discretionary_discount_currency' => null,
            'net_paid_amount_minor' => null,
            'net_paid_amount_currency' => null,
            'unit_cost_minor' => null,
            'unit_cost_currency' => null,
            'sold_attributes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->repository()->findByIdWithSaleLines($transactionModel->id);
    }
}
