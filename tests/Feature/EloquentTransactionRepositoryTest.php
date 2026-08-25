<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
