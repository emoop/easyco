<?php

namespace EasyCo\OperationalSales\Tests;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    private function money(int $minorUnits = 1000): Money
    {
        return Money::fromMinorUnits($minorUnits, 'EUR');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25 10:00:00');
    }

    private function saleLine(?string $id, string $transactionId): SaleLine
    {
        $line = new SaleLine(
            id: null,
            transactionId: $transactionId,
            clientId: 'client-1',
            priceableId: 'priceable-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money(),
            profit: $this->money(200),
            recordedAt: $this->now(),
            effectiveAt: $this->now(),
        );

        if ($id !== null) {
            $line->assignId($id);
        }

        return $line;
    }

    public function test_creating_a_transaction_with_a_channel_works(): void
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);

        $this->assertNull($transaction->id());
        $this->assertSame(Channel::POS, $transaction->channel());
        $this->assertSame([], $transaction->saleLines());
    }

    public function test_add_sale_line_attaches_correctly(): void
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);
        $line = $this->saleLine(null, 'txn-1');

        $transaction->addSaleLine($line);

        $this->assertSame([$line], $transaction->saleLines());
    }

    public function test_add_sale_line_rejects_a_line_whose_transaction_id_does_not_match(): void
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);
        $transaction->assignId('txn-1');

        $mismatchedLine = $this->saleLine(null, 'txn-2');

        $this->expectException(\InvalidArgumentException::class);
        $transaction->addSaleLine($mismatchedLine);
    }

    public function test_add_sale_line_accepts_a_matching_line_once_persisted(): void
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);
        $transaction->assignId('txn-1');

        $matchingLine = $this->saleLine(null, 'txn-1');

        $transaction->addSaleLine($matchingLine);

        $this->assertSame([$matchingLine], $transaction->saleLines());
    }

    public function test_a_freshly_created_unpersisted_transaction_accepts_lines_provisionally(): void
    {
        // No id yet on the Transaction, so addSaleLine() cannot compare
        // against a real id — mirrors Product::createSimple() accepting a
        // not-yet-persisted parent id for its Universal variation.
        $transaction = new Transaction(id: null, channel: Channel::WEB);
        $line = $this->saleLine(null, 'not-yet-a-real-transaction-id');

        $transaction->addSaleLine($line);

        $this->assertSame([$line], $transaction->saleLines());
    }

    public function test_assign_id_backfills_transaction_id_on_a_placeholder_sale_line(): void
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);
        $line = $this->saleLine(null, '');

        $transaction->addSaleLine($line);
        $this->assertSame('', $line->transactionId());

        $transaction->assignId('txn-1');

        $this->assertSame('txn-1', $line->transactionId());
    }

    public function test_reconstitute_from_storage_round_trips_correctly_with_multiple_lines(): void
    {
        $lineOne = $this->saleLine('line-1', 'txn-1');
        $lineTwo = $this->saleLine('line-2', 'txn-1');

        $transaction = Transaction::reconstituteFromStorage('txn-1', Channel::POS, [$lineOne, $lineTwo]);

        $this->assertSame('txn-1', $transaction->id());
        $this->assertSame(Channel::POS, $transaction->channel());
        $this->assertSame([$lineOne, $lineTwo], $transaction->saleLines());
    }
}
