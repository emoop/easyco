<?php

namespace EasyCo\Inventory\Tests;

use EasyCo\Inventory\StockLevel;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class StockLevelTest extends TestCase
{
    public function test_valid_construction_succeeds(): void
    {
        $stockLevel = StockLevel::forVariation('42', 10);

        $this->assertNull($stockLevel->id());
        $this->assertSame('42', $stockLevel->variationId());
        $this->assertSame(10, $stockLevel->quantity());
    }

    public function test_quantity_defaults_to_zero(): void
    {
        $stockLevel = StockLevel::forVariation('42');

        $this->assertSame(0, $stockLevel->quantity());
    }

    public function test_a_negative_quantity_is_rejected_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockLevel::forVariation('42', -1);
    }

    public function test_an_empty_variation_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockLevel::forVariation('');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $stockLevel = StockLevel::forVariation('42');
        $stockLevel->assignId('1');

        $this->assertSame('1', $stockLevel->id());

        $this->expectException(LogicException::class);
        $stockLevel->assignId('2');
    }

    public function test_set_quantity_updates_the_quantity(): void
    {
        $stockLevel = StockLevel::forVariation('42', 10);

        $stockLevel->setQuantity(25);

        $this->assertSame(25, $stockLevel->quantity());
    }

    public function test_set_quantity_to_zero_is_allowed(): void
    {
        $stockLevel = StockLevel::forVariation('42', 10);

        $stockLevel->setQuantity(0);

        $this->assertSame(0, $stockLevel->quantity());
    }

    public function test_set_quantity_rejects_a_negative_value(): void
    {
        $stockLevel = StockLevel::forVariation('42', 10);

        $this->expectException(InvalidArgumentException::class);
        $stockLevel->setQuantity(-1);
    }

    public function test_reconstitute_from_storage_rebuilds_a_stock_level_with_its_id_already_set(): void
    {
        $stockLevel = StockLevel::reconstituteFromStorage('7', '42', 15);

        $this->assertSame('7', $stockLevel->id());
        $this->assertSame('42', $stockLevel->variationId());
        $this->assertSame(15, $stockLevel->quantity());
    }
}
